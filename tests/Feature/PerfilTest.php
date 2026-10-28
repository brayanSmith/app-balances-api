<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // contraseña de la factory: "password"
        $this->user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    }

    public function test_cambia_el_nombre_sin_pedir_la_contrasena(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/perfil', ['name' => 'Ana María', 'email' => 'ana@example.com'])
            ->assertOk()
            ->assertJsonPath('name', 'Ana María')
            ->assertJsonPath('foto_url', null)
            ->assertJsonStructure(['tenant'])
            ->assertJsonMissingPath('foto');

        $this->assertSame('Ana María', $this->user->fresh()->name);
    }

    public function test_cambiar_el_correo_pide_la_contrasena_actual(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/perfil', ['name' => 'Ana', 'email' => 'nuevo@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password_actual');

        $this->putJson('/api/perfil', ['name' => 'Ana', 'email' => 'nuevo@example.com', 'password_actual' => 'mala'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password_actual.0', 'La contraseña no es correcta.');

        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->putJson('/api/perfil', ['name' => 'Ana', 'email' => 'nuevo@example.com', 'password_actual' => 'password'])
            ->assertOk()
            ->assertJsonPath('email', 'nuevo@example.com');

        // El correo nuevo todavía no está verificado.
        $this->assertNull($this->user->fresh()->email_verified_at);
    }

    public function test_no_permite_el_correo_de_otra_cuenta(): void
    {
        User::factory()->create(['email' => 'luis@example.com']);
        Sanctum::actingAs($this->user);

        $this->putJson('/api/perfil', ['name' => 'Ana', 'email' => 'luis@example.com', 'password_actual' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Ese correo ya lo usa otra cuenta.');
    }

    public function test_cambia_la_contrasena_y_cierra_las_otras_sesiones(): void
    {
        $actual = $this->user->createToken('este-celular')->plainTextToken;
        $otro = $this->user->createToken('otro-celular');

        $this->withToken($actual)->putJson('/api/perfil/password', [
            'password_actual' => 'password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('nueva-clave-123', $this->user->fresh()->password));
        $this->assertNull($this->user->tokens()->find($otro->accessToken->id));
        $this->assertSame(1, $this->user->tokens()->count()); // sigue la sesión de este celular
    }

    public function test_valida_el_cambio_de_contrasena(): void
    {
        Sanctum::actingAs($this->user);
        $cambiar = fn (array $datos) => $this->putJson('/api/perfil/password', $datos + [
            'password_actual' => 'password', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123',
        ]);

        $cambiar(['password_actual' => 'mala'])->assertJsonValidationErrors('password_actual');
        $cambiar(['password_confirmation' => 'otra-cosa'])->assertJsonPath('errors.password.0', 'Las contraseñas nuevas no coinciden.');
        $cambiar(['password' => 'corta', 'password_confirmation' => 'corta'])->assertJsonValidationErrors('password');
        $cambiar(['password' => 'password', 'password_confirmation' => 'password'])->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_sube_la_foto_y_se_ve_con_el_enlace_firmado(): void
    {
        Sanctum::actingAs($this->user);

        $url = $this->post('/api/perfil/foto', ['foto' => UploadedFile::fake()->image('yo.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('foto_url');

        $this->assertNotNull($url);
        Storage::disk('local')->assertExists($this->user->fresh()->foto);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // Sin la firma no se puede ver.
        $this->get("/api/perfil/foto/{$this->user->id}")->assertForbidden();
    }

    public function test_una_foto_nueva_borra_la_anterior(): void
    {
        Sanctum::actingAs($this->user);
        $subir = fn () => $this->post('/api/perfil/foto', ['foto' => UploadedFile::fake()->image('yo.png')], ['Accept' => 'application/json']);

        $primera = $subir()->json('foto_url');
        $ruta = $this->user->fresh()->foto;
        $segunda = $subir()->json('foto_url');

        Storage::disk('local')->assertMissing($ruta);
        Storage::disk('local')->assertExists($this->user->fresh()->foto);
        $this->assertNotSame($primera, $segunda);
    }

    public function test_quita_la_foto(): void
    {
        Sanctum::actingAs($this->user);
        $this->post('/api/perfil/foto', ['foto' => UploadedFile::fake()->image('yo.jpg')], ['Accept' => 'application/json']);
        $ruta = $this->user->fresh()->foto;

        $this->deleteJson('/api/perfil/foto')->assertOk()->assertJsonPath('foto_url', null);

        Storage::disk('local')->assertMissing($ruta);
        $this->assertNull($this->user->fresh()->foto);
    }

    public function test_solo_acepta_imagenes(): void
    {
        Sanctum::actingAs($this->user);

        $this->post('/api/perfil/foto', [
            'foto' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('foto');

        $this->post('/api/perfil/foto', [
            'foto' => UploadedFile::fake()->image('enorme.jpg')->size(6000),
        ], ['Accept' => 'application/json'])->assertJsonPath('errors.foto.0', 'La foto no puede pesar más de 5 MB.');
    }

    public function test_requiere_sesion(): void
    {
        $this->putJson('/api/perfil', ['name' => 'X', 'email' => 'x@example.com'])->assertUnauthorized();
        $this->postJson('/api/perfil/foto')->assertUnauthorized();
    }
}
