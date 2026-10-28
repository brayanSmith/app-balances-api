<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CodigoRecuperacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RecuperarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['email' => 'ana@example.com', 'email_verified_at' => null]);
    }

    /** Pide un código y devuelve el que llegó al correo. */
    private function pedirCodigo(): string
    {
        $this->postJson('/api/password/codigo', ['email' => 'ana@example.com'])->assertOk();

        $codigo = null;
        Notification::assertSentTo($this->user, CodigoRecuperacion::class, function ($n) use (&$codigo) {
            $codigo = $n->codigo;

            return true;
        });

        return $codigo;
    }

    private function restablecer(string $codigo, string $password = 'nueva-clave-123')
    {
        return $this->postJson('/api/password/restablecer', [
            'email' => 'ana@example.com', 'codigo' => $codigo, 'password' => $password,
        ]);
    }

    public function test_envia_un_codigo_de_6_digitos_guardado_cifrado(): void
    {
        $codigo = $this->pedirCodigo();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo);
        $guardado = $this->app['db']->table('password_reset_tokens')->where('email', 'ana@example.com')->value('token');
        $this->assertNotSame($codigo, $guardado);
        $this->assertTrue(Hash::check($codigo, $guardado));
    }

    public function test_responde_igual_si_el_correo_no_existe(): void
    {
        $existe = $this->postJson('/api/password/codigo', ['email' => 'ana@example.com'])->assertOk()->json();
        $noExiste = $this->postJson('/api/password/codigo', ['email' => 'nadie@example.com'])->assertOk()->json();

        $this->assertSame($existe, $noExiste);
        Notification::assertSentTimes(CodigoRecuperacion::class, 1);
    }

    public function test_con_el_codigo_cambia_la_contrasena_y_entra(): void
    {
        $viejo = $this->user->createToken('otro-celular');
        $codigo = $this->pedirCodigo();

        $this->restablecer($codigo)
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@example.com')
            ->assertJsonStructure(['user' => ['tenant'], 'token']);

        $this->user->refresh();
        $this->assertTrue(Hash::check('nueva-clave-123', $this->user->password));
        $this->assertNotNull($this->user->email_verified_at);
        // Se cerró la sesión en los demás dispositivos y el código ya no sirve.
        $this->assertNull($this->user->tokens()->find($viejo->accessToken->id));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ana@example.com']);

        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'nueva-clave-123'])->assertOk();
    }

    public function test_el_codigo_no_se_puede_usar_dos_veces(): void
    {
        $codigo = $this->pedirCodigo();

        $this->restablecer($codigo)->assertOk();
        $this->restablecer($codigo, 'otra-clave-456')->assertUnprocessable()->assertJsonValidationErrors('codigo');
    }

    public function test_rechaza_un_codigo_incorrecto(): void
    {
        $codigo = $this->pedirCodigo();
        $incorrecto = $codigo === '000000' ? '111111' : '000000';

        $this->restablecer($incorrecto)
            ->assertUnprocessable()
            ->assertJsonPath('errors.codigo.0', 'El código no es correcto.');
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_el_codigo_vence_a_los_15_minutos(): void
    {
        $codigo = $this->pedirCodigo();

        $this->travel(16)->minutes();

        $this->restablecer($codigo)->assertUnprocessable()->assertJsonValidationErrors('codigo');
    }

    public function test_tras_5_intentos_fallidos_el_codigo_deja_de_servir(): void
    {
        $codigo = $this->pedirCodigo();
        $incorrecto = $codigo === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->restablecer($incorrecto)->assertJsonPath('errors.codigo.0', 'El código no es correcto.');
        }
        $this->restablecer($incorrecto)->assertJsonPath('errors.codigo.0', 'Demasiados intentos. Pide un código nuevo.');

        // Ni el correcto sirve ya: hay que pedir otro.
        $this->restablecer($codigo)->assertUnprocessable();
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_no_reenvia_el_correo_antes_de_un_minuto(): void
    {
        $this->pedirCodigo();
        $this->postJson('/api/password/codigo', ['email' => 'ana@example.com'])->assertOk();
        Notification::assertSentTimes(CodigoRecuperacion::class, 1);

        $this->travel(61)->seconds();
        $this->postJson('/api/password/codigo', ['email' => 'ana@example.com'])->assertOk();
        Notification::assertSentTimes(CodigoRecuperacion::class, 2);
    }

    public function test_un_codigo_nuevo_reemplaza_al_anterior(): void
    {
        $primero = $this->pedirCodigo();
        $this->travel(61)->seconds();
        $this->postJson('/api/password/codigo', ['email' => 'ana@example.com'])->assertOk();

        if ($primero !== $this->ultimoCodigo()) {
            $this->restablecer($primero)->assertUnprocessable();
        }
        $this->restablecer($this->ultimoCodigo())->assertOk();
    }

    public function test_valida_la_nueva_contrasena(): void
    {
        $codigo = $this->pedirCodigo();

        $this->restablecer($codigo, 'corta')->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    private function ultimoCodigo(): string
    {
        return Notification::sent($this->user, CodigoRecuperacion::class)->last()->codigo;
    }
}
