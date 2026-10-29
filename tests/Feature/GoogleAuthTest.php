<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENTE = 'cliente-web.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_ids' => self::CLIENTE.',otro-cliente.apps.googleusercontent.com']);
    }

    /** Lo que respondería Google al verificar el token (se puede cambiar cualquier campo). */
    private function datosGoogle(array $cambios = []): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENTE,
            'sub' => '1234567890',
            'email' => 'ana@gmail.com',
            'email_verified' => 'true',
            'name' => 'Ana Pérez',
            'exp' => (string) (now()->getTimestamp() + 3600),
            ...$cambios,
        ];
    }

    private function google(array $cambios = [], int $estado = 200): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response($this->datosGoogle($cambios), $estado)]);
    }

    private function entrar()
    {
        return $this->postJson('/api/auth/google', ['id_token' => 'token-de-google']);
    }

    public function test_crea_la_cuenta_la_primera_vez(): void
    {
        $this->google();

        $this->entrar()
            ->assertCreated()
            ->assertJsonPath('user.name', 'Ana Pérez')
            ->assertJsonPath('user.email', 'ana@gmail.com')
            ->assertJsonPath('user.tiene_password', false)
            ->assertJsonMissingPath('user.google_id')
            ->assertJsonStructure(['token', 'user' => ['tenant']]);

        $user = User::sole();
        $this->assertSame('1234567890', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        // Como cualquier cuenta nueva (config/balances.php): solo en el teléfono, sin datos en el servidor.
        $this->assertSame('local', $user->almacenamiento);
        $this->assertSame(0, $user->tenant->categorias()->count());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'tokeninfo') && $r['id_token'] === 'token-de-google');
    }

    public function test_la_segunda_vez_entra_a_la_misma_cuenta(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::sequence()
            ->push($this->datosGoogle())
            // Aunque después cambie el correo en Google, se reconoce por su id.
            ->push($this->datosGoogle(['email' => 'ana.nueva@gmail.com'])),
        ]);

        $this->entrar()->assertCreated();
        $this->entrar()->assertOk()->assertJsonPath('user.email', 'ana@gmail.com');

        $this->assertSame(1, User::count());
    }

    public function test_vincula_una_cuenta_existente_con_el_mismo_correo(): void
    {
        $existente = User::factory()->create(['email' => 'ana@gmail.com', 'email_verified_at' => null]);
        $this->google();

        $this->entrar()->assertOk()->assertJsonPath('user.id', $existente->id)->assertJsonPath('user.tiene_password', true);

        $existente->refresh();
        $this->assertSame('1234567890', $existente->google_id);
        $this->assertNotNull($existente->email_verified_at);
        $this->assertTrue(Hash::check('password', $existente->password)); // conserva su contraseña
    }

    /** @return array<string, array{0: array<string, string>, 1: int}> */
    public static function tokensInvalidos(): array
    {
        return [
            'de otra app' => [['aud' => 'cliente-de-otra-app.apps.googleusercontent.com'], 200],
            'vencido' => [['exp' => '1000'], 200],
            'de otro emisor' => [['iss' => 'https://evil.example.com'], 200],
            'que Google rechaza' => [['error' => 'invalid_token'], 400],
        ];
    }

    #[DataProvider('tokensInvalidos')]
    public function test_rechaza_tokens_invalidos(array $cambios, int $estado): void
    {
        $this->google($cambios, $estado);

        $this->entrar()->assertUnprocessable()->assertJsonValidationErrors('id_token');
        $this->assertSame(0, User::count());
    }

    public function test_exige_el_correo_verificado(): void
    {
        $this->google(['email_verified' => 'false']);

        $this->entrar()->assertJsonPath('errors.id_token.0', 'Tu cuenta de Google no tiene el correo verificado.');
    }

    public function test_sin_contrasena_el_login_normal_explica_que_use_google(): void
    {
        $this->google();
        $this->entrar();

        $this->postJson('/api/login', ['email' => 'ana@gmail.com', 'password' => 'lo-que-sea'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'Continuar con Google'));
    }

    public function test_puede_crear_una_contrasena_sin_la_actual_y_luego_entrar_con_ella(): void
    {
        $this->google();
        $token = $this->entrar()->json('token');

        $this->withToken($token)->putJson('/api/perfil/password', [
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertOk();

        $this->postJson('/api/login', ['email' => 'ana@gmail.com', 'password' => 'nueva-clave-123'])->assertOk();
        $this->getJson('/api/user')->assertJsonPath('tiene_password', true);
    }

    public function test_sin_contrasena_no_puede_cambiar_el_correo_ni_borrar_los_datos(): void
    {
        $this->google();
        $this->entrar();
        Sanctum::actingAs(User::sole());

        $this->putJson('/api/perfil', ['name' => 'Ana', 'email' => 'otro@gmail.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password_actual.0', fn ($m) => str_contains($m, 'crea una contraseña'));

        $this->postJson('/api/cuenta/restablecer', ['confirmacion' => 'BORRAR'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', fn ($m) => str_contains($m, 'crea una contraseña'));

        // Cambiar solo el nombre sí se puede.
        $this->putJson('/api/perfil', ['name' => 'Ana María', 'email' => 'ana@gmail.com'])->assertOk();
    }
}
