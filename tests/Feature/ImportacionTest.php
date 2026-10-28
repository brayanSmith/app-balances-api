<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\ImportadorCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    private function csv(string $contenido, string $nombre = 'movimientos.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    /** Formato típico de un banco colombiano: ";", fechas dd/mm/aaaa, montos con signo y puntos de miles. */
    private const BANCO = "Fecha;Descripción;Valor;Categoría\n"
        ."01/10/2026;Pago nómina;3.000.000;Salario\n"
        ."15/10/2026;Recibo energía;-120.000;Servicios\n"
        ."20/10/2026;Mercado;-$ 85.500,50;Mascotas\n";

    public function test_previsualizar_detecta_columnas_formato_y_tipo_por_signo(): void
    {
        $this->post('/api/importaciones/previsualizar', ['archivo' => $this->csv(self::BANCO)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('columnas', ['Fecha', 'Descripción', 'Valor', 'Categoría'])
            ->assertJsonPath('muestras.Valor', ['3.000.000', '-120.000', '-$ 85.500,50'])
            ->assertJsonPath('mapeo.fecha', 'Fecha')
            ->assertJsonPath('mapeo.descripcion', 'Descripción')
            ->assertJsonPath('mapeo.monto', 'Valor')
            ->assertJsonPath('mapeo.categoria', 'Categoría')
            ->assertJsonPath('formato_fecha', 'd/m/Y')
            ->assertJsonPath('total_filas', 3)
            ->assertJsonPath('validas', 3)
            ->assertJsonPath('vista_previa.0.datos.tipo', 'ingreso')
            ->assertJsonPath('vista_previa.0.datos.monto', 3000000)
            ->assertJsonPath('vista_previa.2.datos.tipo', 'gasto')
            ->assertJsonPath('vista_previa.2.datos.monto', 85500.5)
            ->assertJsonPath('vista_previa.2.datos.fecha', '2026-10-20');
    }

    public function test_importa_crea_categorias_que_faltan_y_no_duplica_al_repetir(): void
    {
        $primera = $this->post('/api/importaciones', ['archivo' => $this->csv(self::BANCO)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('importados', 3)
            ->assertJsonPath('duplicados', 0)
            ->assertJsonPath('categorias_creadas', ['Mascotas']);

        $this->assertSame(3, Movimiento::count());
        $this->assertSame(1, Categoria::where('nombre', 'Mascotas')->where('tipo', 'gasto')->count());
        $this->assertDatabaseHas('movimientos', ['importacion_id' => $primera->json('importacion.id'), 'descripcion' => 'Pago nómina']);

        // El mismo archivo otra vez: todo se reconoce como duplicado.
        $this->post('/api/importaciones', ['archivo' => $this->csv(self::BANCO)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('importados', 0)
            ->assertJsonPath('duplicados', 3);
        $this->assertSame(3, Movimiento::count());
    }

    public function test_columnas_de_credito_y_debito_y_formato_de_fecha_americano(): void
    {
        $csv = "Date,Payee,Credit,Debit\n10/25/2026,Salary,\"1,500.00\",\n10/26/2026,Coffee,,4.50\n";

        $this->post('/api/importaciones', [
            'archivo' => $this->csv($csv),
            'formato_fecha' => 'm/d/Y',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('importados', 2);

        $this->assertDatabaseHas('movimientos', ['descripcion' => 'Salary', 'tipo' => 'ingreso', 'monto' => 1500]);
        $this->assertDatabaseHas('movimientos', ['descripcion' => 'Coffee', 'tipo' => 'gasto', 'monto' => 4.5]);
    }

    public function test_mapeo_manual_y_columna_tipo(): void
    {
        $csv = "dia,detalle,plata,clase\n2026-10-05,Arriendo,1200000,egreso\n";

        $this->post('/api/importaciones', [
            'archivo' => $this->csv($csv),
            'mapeo' => ['fecha' => 'dia', 'descripcion' => 'detalle', 'monto' => 'plata', 'tipo' => 'clase'],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseHas('movimientos', ['descripcion' => 'Arriendo', 'tipo' => 'gasto', 'monto' => 1200000]);
    }

    public function test_con_errores_no_importa_nada_salvo_que_se_pida_omitirlos(): void
    {
        $csv = "fecha,descripcion,monto\n2026-10-01,Bien,1000\n31/02/2026,Fecha mala,500\n2026-10-03,,700\n";

        $this->post('/api/importaciones', ['archivo' => $this->csv($csv)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('con_error', 2)
            ->assertJsonPath('errores.0.fila', 3)
            ->assertJsonPath('errores.1.error', 'Falta la descripción');
        $this->assertSame(0, Movimiento::count());

        $this->post('/api/importaciones', ['archivo' => $this->csv($csv), 'omitir_errores' => 1], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('importados', 1)
            ->assertJsonPath('omitidos', 2);
    }

    public function test_lee_archivos_de_excel_en_windows_1252(): void
    {
        $csv = mb_convert_encoding("Fecha;Descripción;Monto\n05/10/2026;Café con leche;-8.000\n", 'Windows-1252', 'UTF-8');

        $this->post('/api/importaciones', ['archivo' => $this->csv($csv)], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseHas('movimientos', ['descripcion' => 'Café con leche', 'monto' => 8000]);
    }

    public function test_exige_mapear_fecha_descripcion_y_monto(): void
    {
        $this->post('/api/importaciones', ['archivo' => $this->csv("a,b\n1,2\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mapeo');
    }

    public function test_deshacer_elimina_los_movimientos_importados(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 5000, 'descripcion' => 'Manual', 'fecha' => '2026-10-02',
        ])->assertCreated();
        $id = $this->post('/api/importaciones', ['archivo' => $this->csv(self::BANCO)], ['Accept' => 'application/json'])
            ->json('importacion.id');

        $this->getJson('/api/importaciones')->assertJsonCount(1, 'data')->assertJsonPath('data.0.importados', 3);
        $this->deleteJson("/api/importaciones/{$id}")->assertNoContent();

        $this->assertSame(['Manual'], Movimiento::pluck('descripcion')->all());
        $this->getJson('/api/importaciones')->assertJsonCount(0, 'data');
    }

    public function test_otro_tenant_no_ve_ni_deshace_mis_importaciones(): void
    {
        $id = $this->post('/api/importaciones', ['archivo' => $this->csv(self::BANCO)], ['Accept' => 'application/json'])
            ->json('importacion.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/importaciones')->assertJsonCount(0, 'data');
        $this->deleteJson("/api/importaciones/{$id}")->assertNotFound();
    }

    public function test_rechaza_archivos_que_no_son_csv(): void
    {
        $this->post('/api/importaciones/previsualizar', [
            'archivo' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_la_plantilla_se_descarga_sin_sesion(): void
    {
        $this->app['auth']->forgetGuards();

        $respuesta = $this->get('/api/importaciones/plantilla')->assertOk();
        $this->assertStringContainsString('fecha,descripcion,monto,tipo,categoria', $respuesta->streamedContent());
    }

    public function test_interpreta_formatos_de_monto(): void
    {
        $importador = new ImportadorCsv;

        $this->assertSame(1234567.5, $importador->monto('$ 1.234.567,50'));
        $this->assertSame(1234.5, $importador->monto('1,234.50'));
        $this->assertSame(-120000.0, $importador->monto('(120.000)'));
        $this->assertSame(120.5, $importador->monto('120.50'));
        $this->assertSame(12.5, $importador->monto('12,5'));
        $this->assertSame(-4.5, $importador->monto('4.50-'));
        $this->assertNull($importador->monto('abc'));
    }
}
