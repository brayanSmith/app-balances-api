<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\Categoria;
use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cuentas "en la nube" o "solo en el teléfono" (users.almacenamiento), que elige el administrador en /admin.
 */
class AlmacenamientoTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_cuentas_nuevas_guardan_solo_en_el_telefono_y_no_ocupan_el_servidor(): void
    {
        $this->postJson('/api/register', ['name' => 'Ana', 'email' => 'ana@prueba.test', 'password' => 'secreto123'])
            ->assertCreated()
            ->assertJsonPath('user.almacenamiento', 'local');

        // Las categorías predeterminadas las crea la app en el teléfono.
        $this->assertSame(0, Categoria::withoutGlobalScopes()->count());
        Sanctum::actingAs(User::sole());
        $this->getJson('/api/user')->assertJsonPath('almacenamiento', 'local');
    }

    public function test_con_almacenamiento_inicial_nube_las_cuentas_nuevas_sincronizan(): void
    {
        config(['balances.almacenamiento_inicial' => 'nube']);

        $this->postJson('/api/register', ['name' => 'Ana', 'email' => 'ana@prueba.test', 'password' => 'secreto123'])
            ->assertCreated()
            ->assertJsonPath('user.almacenamiento', 'nube');

        $this->assertSame(14, Categoria::withoutGlobalScopes()->count());
    }

    public function test_un_valor_desconocido_en_la_configuracion_deja_la_cuenta_en_el_telefono(): void
    {
        config(['balances.almacenamiento_inicial' => 'otro']);

        $this->postJson('/api/register', ['name' => 'Ana', 'email' => 'ana@prueba.test', 'password' => 'secreto123'])
            ->assertJsonPath('user.almacenamiento', 'local');
    }

    public function test_el_almacenamiento_no_se_cambia_desde_la_api(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/perfil', ['name' => 'Otro', 'email' => $user->email, 'almacenamiento' => 'local'])->assertOk();

        $this->assertSame('nube', $user->fresh()->almacenamiento);
    }

    public function test_una_cuenta_local_puede_bajar_pero_no_subir(): void
    {
        Sanctum::actingAs($this->usuarioLocal());

        $this->getJson('/api/sync')->assertOk();
        $this->postJson('/api/sync', ['changes' => []])->assertForbidden();
    }

    public function test_liberar_la_nube_borra_los_datos_y_archivos_de_la_cuenta_local(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/movimientos', ['tipo' => 'gasto', 'monto' => 5000, 'descripcion' => 'Pan', 'fecha' => '2026-10-27'])
            ->json('data.id');
        $this->postJson("/api/movimientos/{$id}/adjuntos", ['archivo' => UploadedFile::fake()->image('factura.jpg')])->assertCreated();
        $ruta = Adjunto::sole()->ruta;
        $otro = User::factory()->create(); // sus datos no se tocan

        $user->forceFill(['almacenamiento' => 'local'])->save();
        $this->postJson('/api/cuenta/liberar-nube')->assertNoContent();

        $this->assertSame(0, Movimiento::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame(0, Categoria::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->count());
        $this->assertSame(14, Categoria::withoutGlobalScopes()->where('tenant_id', $otro->tenant_id)->count());
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_una_cuenta_en_la_nube_no_puede_liberar_sus_datos(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/cuenta/liberar-nube')->assertUnprocessable()->assertJsonValidationErrors('almacenamiento');
        $this->assertSame(14, Categoria::count());
    }

    public function test_restablecer_una_cuenta_local_no_vuelve_a_crear_datos_en_el_servidor(): void
    {
        $user = $this->usuarioLocal();
        Categoria::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->delete(); // ya liberada
        Sanctum::actingAs($user);

        $this->postJson('/api/cuenta/restablecer', ['confirmacion' => 'BORRAR', 'password' => 'password'])->assertOk();

        $this->assertSame(0, Categoria::count());
    }

    private function usuarioLocal(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['almacenamiento' => 'local'])->save();

        return $user;
    }
}
