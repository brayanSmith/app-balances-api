<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * En Laravel Cloud los archivos van al bucket, que es el disco por defecto. Aquí se
 * simula con otro disco como predeterminado: nada debe quedar en el disco "local".
 */
class DiscoArchivosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('bucket');
        config(['filesystems.default' => 'bucket']);

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_los_comprobantes_y_la_foto_van_al_disco_por_defecto(): void
    {
        $id = $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 1000, 'descripcion' => 'Mercado', 'fecha' => '2026-10-01',
        ])->json('data.id');
        $this->post("/api/movimientos/{$id}/adjuntos", [
            'archivo' => UploadedFile::fake()->image('recibo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $adjunto = Adjunto::sole();
        Storage::disk('bucket')->assertExists($adjunto->ruta);
        $this->get($adjunto->urlFirmada())->assertOk();

        $url = $this->post('/api/perfil/foto', [
            'foto' => UploadedFile::fake()->image('yo.jpg'),
        ], ['Accept' => 'application/json'])->json('foto_url');
        Storage::disk('bucket')->assertExists($this->user->fresh()->foto);
        $this->get($url)->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_el_zip_se_extrae_al_disco_por_defecto_y_se_importa_desde_ahi(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('carpeta/recibo.jpg', UploadedFile::fake()->image('x.jpg')->get());
        $zip->close();

        $token = $this->post('/api/importaciones/comprimidos', [
            'comprimido' => new UploadedFile($ruta, 'soportes.zip', 'application/zip', null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->json('token');

        $carpeta = "tmp/importaciones/{$this->user->tenant_id}/{$token}";
        Storage::disk('bucket')->assertExists("{$carpeta}/recibo.jpg");

        $this->post('/api/importaciones', [
            'archivo' => UploadedFile::fake()->createWithContent('m.csv', "fecha,descripcion,monto,adjunto\n2026-10-01,Pago,-5000,recibo.jpg\n"),
            'comprimido' => $token,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('adjuntos_importados', 1);

        Storage::disk('bucket')->assertExists(Adjunto::sole()->ruta);
        Storage::disk('bucket')->assertMissing($carpeta); // la temporal se borra al importar
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
