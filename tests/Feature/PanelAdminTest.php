<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Panel web (/admin, Inertia + React): solo el super admin; lista los usuarios de
 * todos los tenants y elige dónde guarda cada uno sus datos.
 */
class PanelAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_sesion_lleva_al_login_y_la_raiz_al_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/usuarios')->assertRedirect(route('login'));
    }

    public function test_un_usuario_de_la_app_no_puede_entrar_aunque_su_contrasena_sea_correcta(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Esta cuenta no tiene acceso al panel de administración.']);

        $this->assertGuest();
    }

    public function test_quien_entro_solo_con_google_no_puede_entrar(): void
    {
        $user = User::factory()->superAdmin()->create();
        $user->forceFill(['password' => null])->save();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])->assertSessionHasErrors('password');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'cualquiera'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_una_sesion_sin_permiso_recibe_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get(route('profile.edit'))->assertForbidden();
    }

    public function test_el_registro_y_la_recuperacion_por_correo_no_existen(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_el_inicio_muestra_las_cifras_de_todas_las_cuentas(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $nube = User::factory()->create();
        $local = User::factory()->create();
        $local->forceFill(['almacenamiento' => 'local'])->save();
        $movimiento = $this->movimientoDe($nube);
        Adjunto::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $nube->tenant_id, 'movimiento_id' => $movimiento->id,
            'nombre' => 'factura.jpg', 'ruta' => 'adjuntos/x.jpg', 'mime' => 'image/jpeg', 'tamano' => 2097152,
        ]);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inicio')
                ->where('estadisticas.cuentas_nube', 2) // el admin y $nube
                ->where('estadisticas.cuentas_local', 1)
                ->where('estadisticas.movimientos', 1)
                ->where('estadisticas.comprobantes', 1)
                ->where('estadisticas.comprobantes_bytes', 2097152)
                ->has('recientes', 3));
    }

    public function test_lista_las_cuentas_de_todos_los_tenants_con_sus_movimientos(): void
    {
        $admin = User::factory()->superAdmin()->create(['name' => 'Admin']);
        $ana = User::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@prueba.test']);
        $this->movimientoDe($ana);
        $this->movimientoDe($ana);

        $this->actingAs($admin)->get('/admin/usuarios')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/usuarios')
                ->has('usuarios.data', 2)
                ->where('usuarios.total', 2));

        $this->actingAs($admin)->get('/admin/usuarios?q=ana@prueba')
            ->assertInertia(fn (Assert $page) => $page
                ->has('usuarios.data', 1)
                ->where('usuarios.data.0.name', 'Ana Pérez')
                ->where('usuarios.data.0.movimientos', 2)
                ->where('usuarios.data.0.es_super_admin', false)
                ->where('filtros.q', 'ana@prueba'));

        $this->actingAs($admin)->get('/admin/usuarios?almacenamiento=local')
            ->assertInertia(fn (Assert $page) => $page->has('usuarios.data', 0));
    }

    public function test_cambia_el_almacenamiento_de_una_cuenta(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->from('/admin/usuarios')
            ->patch(route('admin.usuarios.almacenamiento', $user), ['almacenamiento' => 'local'])
            ->assertRedirect('/admin/usuarios');
        $this->assertSame('local', $user->fresh()->almacenamiento);

        $this->actingAs($admin)
            ->patch(route('admin.usuarios.almacenamiento', $user), ['almacenamiento' => 'otro'])
            ->assertSessionHasErrors('almacenamiento');
        $this->assertSame('local', $user->fresh()->almacenamiento);
    }

    public function test_un_usuario_de_la_app_no_puede_cambiar_el_almacenamiento(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('admin.usuarios.almacenamiento', $user), ['almacenamiento' => 'local'])
            ->assertForbidden();
        $this->assertSame('nube', $user->fresh()->almacenamiento);
    }

    private function movimientoDe(User $user): Movimiento
    {
        return Movimiento::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
            'tipo' => 'gasto', 'monto' => 1000, 'descripcion' => 'Pan', 'fecha' => '2026-10-27',
        ]);
    }
}
