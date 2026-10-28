<?php

namespace Tests\Feature;

use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportacionProgramadosTest extends TestCase
{
    use RefreshDatabase;

    private const ENCABEZADO = "fecha,descripcion,monto,programado,cada_cuanto,tipo_periodo,hasta\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-26');
        Sanctum::actingAs(User::factory()->create());
    }

    private function importar(string $filas)
    {
        return $this->post('/api/importaciones', [
            'archivo' => UploadedFile::fake()->createWithContent('movimientos.csv', self::ENCABEZADO.$filas),
        ], ['Accept' => 'application/json']);
    }

    public function test_detecta_las_columnas_y_la_vista_previa_muestra_la_regla(): void
    {
        $csv = self::ENCABEZADO
            ."2026-10-05,Arriendo,-1200000,SI,1,meses,\n"
            ."2026-10-06,Gimnasio,-90000,si,2,Semanas,2027-06-30\n"
            ."2026-10-07,Café,-8000,NO,,,\n"
            ."2026-10-08,Seguro,-500000,SI,,años,\n";

        $this->post('/api/importaciones/previsualizar', [
            'archivo' => UploadedFile::fake()->createWithContent('m.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('mapeo.programado', 'programado')
            ->assertJsonPath('mapeo.intervalo', 'cada_cuanto')
            ->assertJsonPath('mapeo.periodo', 'tipo_periodo')
            ->assertJsonPath('mapeo.hasta', 'hasta')
            ->assertJsonPath('vista_previa.0.datos.repetir', ['intervalo' => 1, 'frecuencia' => 'meses'])
            ->assertJsonPath('vista_previa.1.datos.repetir', ['intervalo' => 2, 'frecuencia' => 'semanas'])
            ->assertJsonPath('vista_previa.1.datos.hasta', '2027-06-30')
            ->assertJsonPath('vista_previa.2.datos.repetir', null)
            ->assertJsonPath('vista_previa.3.datos.repetir', ['intervalo' => 1, 'frecuencia' => 'anios']); // vacío = 1
    }

    public function test_reconoce_encabezados_con_tildes_y_espacios(): void
    {
        $csv = "Fecha;Descripción;Valor;¿Programado?;Cada cuánto;Tipo de período\n05/10/2026;Arriendo;-1.200.000;SI;1;mes\n";

        $this->post('/api/importaciones/previsualizar', [
            'archivo' => UploadedFile::fake()->createWithContent('m.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertJsonPath('mapeo.programado', '¿Programado?')
            ->assertJsonPath('mapeo.intervalo', 'Cada cuánto')
            ->assertJsonPath('mapeo.periodo', 'Tipo de período')
            ->assertJsonPath('vista_previa.0.datos.repetir', ['intervalo' => 1, 'frecuencia' => 'meses']);
    }

    public function test_una_serie_crea_una_sola_programacion_desde_el_ultimo_pago(): void
    {
        $filas = "2026-08-05,Arriendo,-1200000,SI,1,meses,\n"
            ."2026-09-05,Arriendo,-1200000,SI,1,meses,\n"
            ."2026-10-05,Arriendo,-1200000,SI,1,meses,\n"
            ."2026-10-07,Café,-8000,NO,,,\n";

        $this->importar($filas)
            ->assertCreated()
            ->assertJsonPath('importados', 4)
            ->assertJsonPath('programaciones_creadas', 1);

        $programacion = Programacion::sole();
        $this->assertSame('2026-10-05', $programacion->base->fecha->toDateString());
        $this->assertSame('2026-11-05', $programacion->proxima_fecha->toDateString());
        $this->assertSame(3, Movimiento::whereNotNull('programacion_id')->count());
        $this->assertNull(Movimiento::where('descripcion', 'Café')->value('programacion_id'));

        // Reimportar el mismo archivo no crea otra programación.
        $this->importar($filas)->assertJsonPath('duplicados', 4)->assertJsonPath('programaciones_creadas', 0);
        $this->assertSame(1, Programacion::count());
    }

    public function test_reutiliza_la_programacion_que_ya_existe(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 1200000, 'descripcion' => 'Arriendo', 'fecha' => '2026-10-05',
            'programado' => true, 'intervalo' => 1, 'frecuencia' => 'meses',
        ])->assertCreated();

        // Historial anterior del mismo arriendo.
        $this->importar("2026-09-05,arriendo,-1200000,SI,1,meses,\n")
            ->assertCreated()
            ->assertJsonPath('programaciones_creadas', 0);

        $programacion = Programacion::sole();
        $this->assertSame(2, $programacion->movimientos()->count());
        $this->assertSame('2026-10-05', $programacion->base->fecha->toDateString()); // la base sigue siendo la más nueva
    }

    public function test_un_pago_mas_reciente_pasa_a_ser_la_base(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 1200000, 'descripcion' => 'Arriendo', 'fecha' => '2026-09-05',
            'programado' => true, 'intervalo' => 1, 'frecuencia' => 'meses',
        ])->assertCreated();

        $this->importar("2026-10-20,Arriendo,-1300000,SI,2,meses,\n")->assertCreated();

        $programacion = Programacion::sole();
        $this->assertEquals(1300000, $programacion->base->monto);
        $this->assertSame(2, $programacion->intervalo);
        $this->assertSame('2026-12-20', $programacion->proxima_fecha->toDateString());
    }

    public function test_hasta_define_el_fin_de_la_programacion(): void
    {
        $this->importar("2026-10-15,Cuota carro,-850000,SI,1,meses,2027-03-15\n")->assertCreated();

        $this->assertSame('2027-03-15', Programacion::sole()->fecha_fin->toDateString());
    }

    public function test_valores_invalidos_son_errores_de_la_fila(): void
    {
        $this->importar(
            "2026-10-01,A,-1000,tal vez,1,meses,\n"
            ."2026-10-02,B,-1000,SI,dos,meses,\n"
            ."2026-10-03,C,-1000,SI,1,quincenas,\n"
            ."2026-10-04,D,-1000,SI,1,,\n"
            ."2026-10-05,E,-1000,SI,1,meses,2026-09-01\n"
            ."2026-10-06,F,-1000,NO,dos,quincenas,\n" // con NO se ignora el resto
        )
            ->assertUnprocessable()
            ->assertJsonPath('con_error', 5)
            ->assertJsonPath('errores.0.error', 'Programado debe ser SI o NO (dice "tal vez")')
            ->assertJsonPath('errores.1.error', 'Cada cuánto debe ser un número entero entre 1 y 365 (dice "dos")')
            ->assertJsonPath('errores.2.error', 'Tipo de periodo no válido: "quincenas" (usa dias, semanas, meses o anios)')
            ->assertJsonPath('errores.3.error', 'Falta el tipo de periodo: dias, semanas, meses o anios');
    }

    public function test_deshacer_quita_tambien_la_programacion(): void
    {
        $id = $this->importar("2026-09-05,Arriendo,-1200000,SI,1,meses,\n2026-10-05,Arriendo,-1200000,SI,1,meses,\n")
            ->json('importacion.id');

        $this->deleteJson("/api/importaciones/{$id}")->assertNoContent();

        $this->assertSame(0, Programacion::count());
        $this->assertSame(0, Movimiento::count());
    }
}
