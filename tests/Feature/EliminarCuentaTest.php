<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\Categoria;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EliminarCuentaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // contraseña de la factory: "password"
        $this->user = User::factory()->create(['email' => 'ana@example.com']);
        $this->token = $this->user->createToken('celular')->plainTextToken;
    }

    /** Un poco de todo: movimiento programado con comprobante y foto de perfil. */
    private function llenarCuenta(): void
    {
        $id = $this->withToken($this->token)->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 1200000, 'descripcion' => 'Arriendo', 'fecha' => '2026-10-05',
            'programado' => true, 'intervalo' => 1, 'frecuencia' => 'meses',
        ])->assertCreated()->json('data.id');
        $this->withToken($this->token)->post("/api/movimientos/{$id}/adjuntos", [
            'archivo' => UploadedFile::fake()->image('recibo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->withToken($this->token)->post('/api/perfil/foto', [
            'foto' => UploadedFile::fake()->image('yo.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    private function assertTodoBorrado(int $tenantId): void
    {
        $this->assertNull(User::find($this->user->id));
        $this->assertNull(Tenant::find($tenantId));
        foreach ([Movimiento::class, Programacion::class, Categoria::class, Adjunto::class] as $modelo) {
            $this->assertSame(0, $modelo::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(), $modelo);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_desde_la_app_borra_la_cuenta_y_todos_sus_datos(): void
    {
        $this->llenarCuenta();
        $tenantId = $this->user->tenant_id;
        $this->assertNotEmpty(Storage::disk('local')->allFiles());

        $this->withToken($this->token)
            ->deleteJson('/api/cuenta', ['confirmacion' => 'ELIMINAR', 'password' => 'password'])
            ->assertNoContent();

        $this->assertTodoBorrado($tenantId);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_pide_escribir_eliminar_y_la_contrasena(): void
    {
        $this->withToken($this->token)->deleteJson('/api/cuenta', ['confirmacion' => 'eliminar', 'password' => 'password'])
            ->assertJsonValidationErrors('confirmacion');
        $this->withToken($this->token)->deleteJson('/api/cuenta', ['confirmacion' => 'ELIMINAR'])
            ->assertJsonPath('errors.password.0', 'Escribe tu contraseña para confirmar.');
        $this->withToken($this->token)->deleteJson('/api/cuenta', ['confirmacion' => 'ELIMINAR', 'password' => 'mala'])
            ->assertJsonPath('errors.password.0', 'La contraseña no es correcta.');

        $this->assertNotNull($this->user->fresh());
    }

    public function test_quien_entro_con_google_sin_contrasena_solo_escribe_eliminar(): void
    {
        $this->user->forceFill(['password' => null, 'google_id' => '123'])->save();

        $this->withToken($this->token)->deleteJson('/api/cuenta', ['confirmacion' => 'ELIMINAR'])->assertNoContent();

        $this->assertNull($this->user->fresh());
    }

    public function test_no_toca_las_demas_cuentas(): void
    {
        $otro = User::factory()->create();
        $movimientoAjeno = Movimiento::forceCreate([
            'tenant_id' => $otro->tenant_id, 'user_id' => $otro->id, 'tipo' => 'gasto',
            'monto' => 1000, 'descripcion' => 'Ajeno', 'fecha' => '2026-10-01',
        ]);

        $this->withToken($this->token)
            ->deleteJson('/api/cuenta', ['confirmacion' => 'ELIMINAR', 'password' => 'password'])
            ->assertNoContent();

        $this->assertNotNull($otro->fresh());
        $this->assertNotNull(Movimiento::withoutGlobalScopes()->find($movimientoAjeno->id));
        $this->assertGreaterThan(0, Categoria::withoutGlobalScopes()->where('tenant_id', $otro->tenant_id)->count());
    }

    public function test_la_politica_de_privacidad_es_publica(): void
    {
        $this->get('/privacidad')
            ->assertOk()
            ->assertSee('Política de privacidad')
            ->assertSee('Mi perfil → Eliminar cuenta', false)
            ->assertSee(route('eliminar-cuenta'));
    }

    public function test_desde_la_web_borra_la_cuenta_con_correo_y_contrasena(): void
    {
        $this->llenarCuenta();
        $tenantId = $this->user->tenant_id;

        $this->get('/eliminar-cuenta')->assertOk()->assertSee('Eliminar mi cuenta');

        $this->post('/eliminar-cuenta', ['email' => 'ana@example.com', 'password' => 'password', 'confirmo' => '1'])
            ->assertRedirect('/eliminar-cuenta')
            ->assertSessionHas('eliminada');

        $this->assertTodoBorrado($tenantId);
        $this->followingRedirects()->get('/eliminar-cuenta')->assertOk();
    }

    public function test_desde_la_web_rechaza_datos_incorrectos_o_sin_confirmar(): void
    {
        $this->post('/eliminar-cuenta', ['email' => 'ana@example.com', 'password' => 'mala', 'confirmo' => '1'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);
        $this->post('/eliminar-cuenta', ['email' => 'nadie@example.com', 'password' => 'password', 'confirmo' => '1'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);
        $this->post('/eliminar-cuenta', ['email' => 'ana@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('confirmo');

        $this->assertNotNull($this->user->fresh());
    }

    public function test_desde_la_web_explica_que_hacer_si_entro_solo_con_google(): void
    {
        $this->user->forceFill(['password' => null, 'google_id' => '123'])->save();

        $this->post('/eliminar-cuenta', ['email' => 'ana@example.com', 'password' => 'x', 'confirmo' => '1'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('entra con Google', session('errors')->first('email'));
        $this->assertNotNull($this->user->fresh());
    }
}
