<?php

namespace Tests\Feature;

use App\Models\Adjunto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;
use ZipArchive;

class ImportacionAdjuntosTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = "fecha,descripcion,monto,adjunto\n"
        ."2026-10-01,Pago arriendo,-50000,80080fd6.Foto_pago.223121.jpg\n"
        ."2026-10-02,Recibo luz,-120000,recibo.pdf|no-existe.jpg\n"
        ."2026-10-03,Sin soporte,-1000,\n";

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, string>  $archivos  ruta dentro del ZIP → contenido
     */
    private function zip(array $archivos, string $nombre = 'soportes.zip'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        foreach ($archivos as $interna => $contenido) {
            $zip->addFromString($interna, $contenido);
        }
        $zip->close();

        return new UploadedFile($ruta, $nombre, 'application/zip', null, true);
    }

    private function zipDePrueba(): UploadedFile
    {
        $jpg = UploadedFile::fake()->image('x.jpg')->get();

        return $this->zip([
            'comprobantes/2026/80080fd6.Foto_pago.223121.jpg' => $jpg, // en subcarpeta: se aplana
            'RECIBO.PDF' => "%PDF-1.4\n%prueba\n",                    // mayúsculas: igual coincide
            'notas.txt' => 'no es un adjunto',
            '../../fuera.jpg' => $jpg,                                  // intento de salir de la carpeta
            '__MACOSX/._basura.jpg' => 'x',
        ]);
    }

    private function subirComprimido(UploadedFile $archivo)
    {
        return $this->post('/api/importaciones/comprimidos', ['comprimido' => $archivo], ['Accept' => 'application/json']);
    }

    private function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('movimientos.csv', self::CSV);
    }

    public function test_sube_el_zip_y_extrae_solo_imagenes_y_pdf_sin_carpetas(): void
    {
        $respuesta = $this->subirComprimido($this->zipDePrueba())
            ->assertCreated()
            ->assertJsonPath('archivos', 3)
            ->assertJsonPath('omitidos', ['notas.txt (no es imagen ni PDF)']);

        $carpeta = "tmp/importaciones/{$this->user->tenant_id}/{$respuesta->json('token')}";
        Storage::disk('local')->assertExists("{$carpeta}/80080fd6.Foto_pago.223121.jpg");
        Storage::disk('local')->assertExists("{$carpeta}/fuera.jpg"); // quedó dentro, no dos niveles arriba
        Storage::disk('local')->assertMissing('tmp/importaciones/fuera.jpg');
        $this->assertCount(3, Storage::disk('local')->allFiles($carpeta));
    }

    public function test_la_vista_previa_indica_que_adjuntos_se_encontraron(): void
    {
        $token = $this->subirComprimido($this->zipDePrueba())->json('token');

        $this->post('/api/importaciones/previsualizar', [
            'archivo' => $this->csv(),
            'comprimido' => $token,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('mapeo.adjunto', 'adjunto')
            ->assertJsonPath('adjuntos.referenciados', 3)
            ->assertJsonPath('adjuntos.encontrados', 2)
            ->assertJsonPath('adjuntos.faltantes', [['fila' => 3, 'nombre' => 'no-existe.jpg']])
            ->assertJsonPath('vista_previa.0.adjuntos', [['nombre' => '80080fd6.Foto_pago.223121.jpg', 'encontrado' => true]])
            ->assertJsonPath('vista_previa.2.adjuntos', []);
    }

    public function test_importa_y_adjunta_los_archivos_del_zip(): void
    {
        $token = $this->subirComprimido($this->zipDePrueba())->json('token');

        $this->post('/api/importaciones', [
            'archivo' => $this->csv(),
            'comprimido' => $token,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('importados', 3)
            ->assertJsonPath('adjuntos_importados', 2)
            ->assertJsonPath('adjuntos_faltantes', [['fila' => 3, 'nombre' => 'no-existe.jpg']]);

        $foto = Adjunto::where('nombre', '80080fd6.Foto_pago.223121.jpg')->sole();
        $this->assertSame('image/jpeg', $foto->mime);
        $this->assertSame('Pago arriendo', $foto->movimiento->descripcion);
        Storage::disk('local')->assertExists($foto->ruta);
        $this->assertSame('application/pdf', Adjunto::where('nombre', 'RECIBO.PDF')->sole()->mime);

        // La carpeta temporal se borra al terminar.
        Storage::disk('local')->assertMissing("tmp/importaciones/{$this->user->tenant_id}/{$token}");

        // Y se ven desde el movimiento como cualquier otro adjunto.
        $this->get($foto->urlFirmada())->assertOk();
    }

    public function test_deshacer_la_importacion_borra_tambien_los_archivos(): void
    {
        $token = $this->subirComprimido($this->zipDePrueba())->json('token');
        $id = $this->post('/api/importaciones', ['archivo' => $this->csv(), 'comprimido' => $token], ['Accept' => 'application/json'])
            ->json('importacion.id');
        $rutas = Adjunto::pluck('ruta')->all();

        $this->deleteJson("/api/importaciones/{$id}")->assertNoContent();

        $this->assertSame(0, Adjunto::count());
        foreach ($rutas as $ruta) {
            Storage::disk('local')->assertMissing($ruta);
        }
    }

    public function test_el_token_de_otro_tenant_no_sirve(): void
    {
        $token = $this->subirComprimido($this->zipDePrueba())->json('token');

        Sanctum::actingAs(User::factory()->create());
        $this->post('/api/importaciones/previsualizar', [
            'archivo' => $this->csv(),
            'comprimido' => $token,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('comprimido');
    }

    public function test_rechaza_comprimidos_sin_adjuntos_o_que_no_son_zip(): void
    {
        $this->subirComprimido($this->zip(['leeme.txt' => 'hola']))
            ->assertUnprocessable()->assertJsonValidationErrors('comprimido');

        $this->subirComprimido(UploadedFile::fake()->create('fotos.7z', 10))
            ->assertUnprocessable()->assertJsonValidationErrors('comprimido');

        $this->subirComprimido(UploadedFile::fake()->createWithContent('roto.zip', 'esto no es un zip'))
            ->assertUnprocessable()->assertJsonValidationErrors('comprimido');
    }

    public function test_rar_sin_7zip_explica_que_hace_falta(): void
    {
        if ((new ExecutableFinder)->find('7z') || is_file('C:\\Program Files\\7-Zip\\7z.exe')) {
            $this->markTestSkipped('7-Zip está instalado en esta máquina.');
        }

        $this->subirComprimido(UploadedFile::fake()->createWithContent('soportes.rar', 'Rar!'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.comprimido.0', 'Para leer archivos RAR el servidor necesita 7-Zip. Mientras tanto, comprime los adjuntos en ZIP.');
    }

    public function test_el_comando_limpia_las_carpetas_temporales_viejas(): void
    {
        $token = $this->subirComprimido($this->zipDePrueba())->json('token');
        $carpeta = "tmp/importaciones/{$this->user->tenant_id}/{$token}";

        $this->artisan('importaciones:limpiar')->assertSuccessful();
        Storage::disk('local')->assertExists($carpeta); // recién subida: se conserva

        $this->travel(2)->days();
        $this->artisan('importaciones:limpiar')->assertSuccessful();
        Storage::disk('local')->assertMissing($carpeta);
    }
}
