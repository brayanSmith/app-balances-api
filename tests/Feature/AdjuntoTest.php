<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdjuntoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());
    }

    private function crearMovimiento(): int
    {
        return $this->postJson('/api/movimientos', [
            'tipo' => 'gasto',
            'monto' => 120000,
            'descripcion' => 'Energía',
            'fecha' => '2026-10-20',
        ])->json('data.id');
    }

    private function subir(int $movimiento, UploadedFile $archivo)
    {
        return $this->postJson("/api/movimientos/{$movimiento}/adjuntos", ['archivo' => $archivo]);
    }

    public function test_subir_una_foto_y_un_pdf(): void
    {
        $id = $this->crearMovimiento();

        $this->subir($id, UploadedFile::fake()->image('factura.jpg'))
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'factura.jpg')
            ->assertJsonPath('data.es_imagen', true);
        $this->subir($id, UploadedFile::fake()->create('recibo.pdf', 200, 'application/pdf'))
            ->assertCreated()
            ->assertJsonPath('data.es_imagen', false);

        $this->getJson("/api/movimientos/{$id}")
            ->assertJsonCount(2, 'data.adjuntos')
            ->assertJsonPath('data.adjuntos_count', 2);
        $this->getJson('/api/movimientos')->assertJsonPath('data.0.adjuntos_count', 2);

        Storage::disk('local')->assertExists(Adjunto::pluck('ruta')->all());
    }

    public function test_rechaza_archivos_no_permitidos_o_muy_grandes(): void
    {
        $id = $this->crearMovimiento();

        $this->subir($id, UploadedFile::fake()->create('virus.exe', 10))
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
        $this->subir($id, UploadedFile::fake()->create('enorme.pdf', 11000, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_el_enlace_firmado_sirve_el_archivo_y_sin_firma_no(): void
    {
        $id = $this->crearMovimiento();
        $url = $this->subir($id, UploadedFile::fake()->image('factura.jpg'))->json('data.url');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $sinFirma = strtok($url, '?');
        $this->get($sinFirma)->assertForbidden();
    }

    public function test_eliminar_el_adjunto_borra_el_archivo(): void
    {
        $id = $this->crearMovimiento();
        $adjunto = $this->subir($id, UploadedFile::fake()->image('factura.jpg'))->json('data.id');
        $ruta = Adjunto::find($adjunto)->ruta;

        $this->deleteJson("/api/adjuntos/{$adjunto}")->assertNoContent();

        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_eliminar_el_movimiento_borra_sus_archivos(): void
    {
        $id = $this->crearMovimiento();
        $this->subir($id, UploadedFile::fake()->image('factura.jpg'));
        $ruta = Adjunto::first()->ruta;

        $this->deleteJson("/api/movimientos/{$id}")->assertNoContent();

        $this->assertDatabaseCount('adjuntos', 0);
        Storage::disk('local')->assertMissing($ruta);
    }

    public function test_otro_tenant_no_puede_subir_ni_borrar(): void
    {
        $id = $this->crearMovimiento();
        $adjunto = $this->subir($id, UploadedFile::fake()->image('factura.jpg'))->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->subir($id, UploadedFile::fake()->image('otra.jpg'))->assertNotFound();
        $this->deleteJson("/api/adjuntos/{$adjunto}")->assertNotFound();
    }
}
