<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\Categoria;
use App\Models\Importacion;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestablecerCuentaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create(); // contraseña de la factory: "password"
    }

    /** Llena la cuenta con un poco de todo. */
    private function llenarCuenta(User $user): void
    {
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 1200000, 'descripcion' => 'Arriendo', 'fecha' => '2026-10-05',
            'programado' => true, 'intervalo' => 1, 'frecuencia' => 'meses',
        ])->json('data.id');
        $this->post("/api/movimientos/{$id}/adjuntos", ['archivo' => UploadedFile::fake()->image('recibo.jpg')], ['Accept' => 'application/json']);
        $this->postJson('/api/categorias', ['tipo' => 'gasto', 'nombre' => 'Mascotas', 'icono' => 'paw', 'color' => '#A855F7']);
        $this->post('/api/importaciones', [
            'archivo' => UploadedFile::fake()->createWithContent('m.csv', "fecha,descripcion,monto\n2026-10-01,Mercado,-50000\n"),
        ], ['Accept' => 'application/json']);
    }

    private function restablecer(array $datos = [])
    {
        return $this->postJson('/api/cuenta/restablecer', $datos + ['confirmacion' => 'BORRAR', 'password' => 'password']);
    }

    public function test_borra_todo_y_vuelven_las_categorias_predeterminadas(): void
    {
        $this->llenarCuenta($this->user);
        $ruta = Adjunto::sole()->ruta;

        $this->restablecer()
            ->assertOk()
            ->assertJsonPath('borrados.movimientos', 2)
            ->assertJsonPath('borrados.adjuntos', 1)
            ->assertJsonPath('borrados.programaciones', 1)
            ->assertJsonPath('borrados.importaciones', 1);

        $this->assertSame(0, Movimiento::count());
        $this->assertSame(0, Adjunto::count());
        $this->assertSame(0, Programacion::count());
        $this->assertSame(0, Importacion::count());
        $this->assertSame(count(Categoria::PREDETERMINADAS), Categoria::count());
        $this->assertFalse(Categoria::where('nombre', 'Mascotas')->exists());
        Storage::disk('local')->assertMissing($ruta);

        // La cuenta y la sesión siguen funcionando.
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $this->user->email);
        $this->getJson('/api/movimientos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_no_toca_los_datos_de_otro_tenant(): void
    {
        $otro = User::factory()->create();
        $this->llenarCuenta($otro);
        $this->llenarCuenta($this->user);

        $this->restablecer()->assertOk();

        Sanctum::actingAs($otro);
        $this->assertSame(2, Movimiento::count());
        $this->assertSame(1, Adjunto::count());
        Storage::disk('local')->assertExists(Adjunto::sole()->ruta);
        $this->assertTrue(Categoria::where('nombre', 'Mascotas')->exists());
    }

    public function test_exige_escribir_borrar_y_la_contrasena_correcta(): void
    {
        $this->llenarCuenta($this->user);

        $this->restablecer(['confirmacion' => 'borrar'])->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->restablecer(['password' => 'otra-clave'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'La contraseña no es correcta.');
        $this->postJson('/api/cuenta/restablecer', [])->assertJsonValidationErrors(['confirmacion', 'password']);

        $this->assertSame(2, Movimiento::count()); // nada se borró
    }

    public function test_requiere_sesion(): void
    {
        $this->restablecer()->assertUnauthorized();
    }
}
