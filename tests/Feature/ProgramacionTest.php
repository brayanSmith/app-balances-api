<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramacionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-26');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function datos(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'gasto',
            'monto' => 120000,
            'descripcion' => 'Energía',
            'categoria_id' => Categoria::where('tipo', 'gasto')->where('nombre', 'Servicios')->value('id'),
            'fecha' => '2026-10-15',
            'programado' => true,
            'intervalo' => 1,
            'frecuencia' => 'meses',
        ], $extra);
    }

    private function crear(array $extra = []): array
    {
        return $this->postJson('/api/movimientos', $this->datos($extra))->assertCreated()->json('data');
    }

    private function pendientes(array $query = [])
    {
        return $this->getJson('/api/programaciones/pendientes?'.http_build_query($query))->assertOk();
    }

    private function pagar(int $programacion, array $datos = [])
    {
        return $this->postJson("/api/programaciones/{$programacion}/pagar", $datos + [
            'monto' => 120000,
            'fecha' => '2026-11-14',
        ]);
    }

    public function test_movimiento_sin_programar(): void
    {
        $movimiento = $this->crear(['programado' => false, 'intervalo' => null, 'frecuencia' => null]);

        $this->assertFalse($movimiento['programado']);
        $this->assertDatabaseCount('programaciones', 0);
    }

    public function test_programado_calcula_el_proximo_vencimiento_desde_el_ultimo_pago(): void
    {
        $movimiento = $this->crear();

        $this->assertSame('2026-11-15', $movimiento['programacion']['proxima_fecha']);
        $this->assertTrue($movimiento['programacion']['es_base']);
    }

    public function test_exige_intervalo_y_frecuencia_si_es_programado(): void
    {
        $this->postJson('/api/movimientos', $this->datos(['intervalo' => null, 'frecuencia' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['intervalo', 'frecuencia']);
    }

    public function test_nada_se_registra_solo_aunque_pase_el_vencimiento(): void
    {
        $this->crear();

        $this->travelTo('2027-02-01');
        $this->getJson('/api/movimientos/resumen?mes=2026-11')->assertJson(['gastos' => 0]);
        $this->assertSame(1, Movimiento::count()); // solo el pago original
    }

    public function test_pendientes_muestra_vencidos_hoy_y_proximos(): void
    {
        $this->crear(['descripcion' => 'Agua', 'fecha' => '2026-09-20']);       // vencía 20 oct: vencido
        $this->crear(['descripcion' => 'Gas', 'fecha' => '2026-09-26']);        // vence hoy
        $this->crear(['descripcion' => 'Internet', 'fecha' => '2026-09-30']);   // vence en 4 días
        $this->crear(['descripcion' => 'Arriendo', 'fecha' => '2026-10-10']);   // vence en 15 días: aún no

        $this->pendientes()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.descripcion', 'Agua')
            ->assertJsonPath('data.0.estado', 'vencido')
            ->assertJsonPath('data.0.dias', -6)
            ->assertJsonPath('data.0.monto', 120000)
            ->assertJsonPath('data.0.categoria.nombre', 'Servicios')
            ->assertJsonPath('data.1.estado', 'hoy')
            ->assertJsonPath('data.2.estado', 'proximo')
            ->assertJsonPath('data.2.dias', 4);

        $this->pendientes(['dias' => 30])->assertJsonCount(4, 'data');
    }

    public function test_con_mes_incluye_todo_lo_que_vence_hasta_fin_de_ese_mes(): void
    {
        $this->crear(['descripcion' => 'Agua', 'fecha' => '2026-09-20']);      // vencido (20 oct)
        $this->crear(['descripcion' => 'Arriendo', 'fecha' => '2026-10-10']);  // 10 nov
        $this->crear(['descripcion' => 'Gas', 'fecha' => '2026-10-28']);       // 28 nov
        $this->crear(['descripcion' => 'Seguro', 'fecha' => '2026-11-05']);    // 5 dic

        // Mes actual (octubre): vencidos + próximos 7 días (nada más vence en octubre).
        $this->pendientes(['mes' => '2026-10'])->assertJsonCount(1, 'data');

        // Noviembre: también lo que vence en noviembre, para pagarlo por adelantado.
        $this->pendientes(['mes' => '2026-11'])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.descripcion', 'Gas')
            ->assertJsonPath('data.2.estado', 'proximo');
    }

    public function test_pagar_por_adelantado_cuenta_en_el_mes_en_que_se_paga(): void
    {
        $programacion = $this->crear(['fecha' => '2026-10-10'])['programacion']['id']; // vence 10 nov

        $this->pagar($programacion, ['fecha' => '2026-10-27'])
            ->assertJsonPath('data.fecha_programada', '2026-11-10')
            ->assertJsonPath('data.programacion.proxima_fecha', '2026-12-10');

        $this->getJson('/api/movimientos/resumen?mes=2026-10')->assertJson(['gastos' => 240000]); // original + adelantado
        $this->pendientes(['mes' => '2026-11'])->assertJsonCount(0, 'data');
    }

    public function test_cuenta_los_periodos_vencidos_si_lleva_meses_sin_pagar(): void
    {
        $this->crear(['fecha' => '2026-07-15']); // vencían 15 ago, 15 sep y 15 oct

        $this->pendientes()
            ->assertJsonPath('data.0.vence', '2026-08-15')
            ->assertJsonPath('data.0.periodos_vencidos', 3);
    }

    public function test_pagar_registra_el_monto_real_y_pasa_al_siguiente_periodo(): void
    {
        $programacion = $this->crear()['programacion']['id'];

        $this->pagar($programacion, ['monto' => 135000])
            ->assertCreated()
            ->assertJsonPath('data.monto', 135000)
            ->assertJsonPath('data.fecha', '2026-11-14')
            ->assertJsonPath('data.fecha_programada', '2026-11-15')
            ->assertJsonPath('data.programacion.proxima_fecha', '2026-12-15')
            ->assertJsonPath('data.programacion.es_base', false);

        // Suma al balance del mes en que se pagó.
        $this->getJson('/api/movimientos/resumen?mes=2026-11')->assertJson(['gastos' => 135000]);
        // El estimado sigue siendo el monto anterior.
        $this->travelTo('2026-12-10');
        $this->pendientes()->assertJsonPath('data.0.monto', 120000);
    }

    public function test_pagar_puede_actualizar_el_monto_de_los_proximos(): void
    {
        $programacion = $this->crear()['programacion']['id'];

        $this->pagar($programacion, ['monto' => 135000, 'actualizar_base' => true])
            ->assertJsonPath('data.programacion.es_base', true);

        $this->travelTo('2026-12-10');
        $this->pendientes()->assertJsonPath('data.0.monto', 135000);
    }

    public function test_el_pago_admite_comprobante(): void
    {
        Storage::fake('local');
        $programacion = $this->crear()['programacion']['id'];

        $id = $this->pagar($programacion)->json('data.id');
        $this->post("/api/movimientos/{$id}/adjuntos", [
            'archivo' => UploadedFile::fake()->image('recibo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->getJson("/api/movimientos/{$id}")->assertJsonPath('data.adjuntos_count', 1);
    }

    public function test_omitir_salta_el_periodo_sin_registrar_nada(): void
    {
        $programacion = $this->crear(['fecha' => '2026-09-20'])['programacion']['id'];

        $this->postJson("/api/programaciones/{$programacion}/omitir")->assertNoContent();

        $this->assertSame(1, Movimiento::count());
        $this->assertSame('2026-11-20', Programacion::find($programacion)->proxima_fecha->toDateString());
    }

    public function test_borrar_el_ultimo_pago_vuelve_a_dejar_pendiente_el_periodo(): void
    {
        $programacion = $this->crear()['programacion']['id'];
        $pago = $this->pagar($programacion)->json('data.id');

        $this->deleteJson("/api/movimientos/{$pago}")->assertNoContent();

        $this->assertSame('2026-11-15', Programacion::find($programacion)->proxima_fecha->toDateString());
    }

    public function test_respeta_fecha_fin_y_deja_de_aparecer(): void
    {
        $programacion = $this->crear(['fecha_fin' => '2026-12-31'])['programacion']['id'];

        $this->pagar($programacion, ['fecha' => '2026-11-15']);
        $this->pagar($programacion, ['fecha' => '2026-12-15'])
            ->assertJsonPath('data.programacion.activo', false); // el siguiente (15 ene) ya pasa de la fecha fin

        $this->travelTo('2027-01-20');
        $this->pendientes()->assertJsonCount(0, 'data');
        $this->pagar($programacion)->assertUnprocessable();
    }

    public function test_editar_este_y_los_siguientes_cambia_la_base(): void
    {
        $programacion = $this->crear()['programacion']['id'];
        $pago = $this->pagar($programacion)->json('data.id');

        $this->putJson("/api/movimientos/{$pago}", $this->datos([
            'monto' => 150000,
            'fecha' => '2026-11-14',
            'actualizar_programado' => true,
        ]))->assertOk()->assertJsonPath('data.programacion.es_base', true);

        $this->travelTo('2026-12-10');
        $this->pendientes()->assertJsonPath('data.0.monto', 150000);
    }

    public function test_cambiar_la_frecuencia_reprograma_desde_el_ultimo_pago(): void
    {
        $movimiento = $this->crear();

        $this->putJson("/api/movimientos/{$movimiento['id']}", $this->datos(['intervalo' => 2]))
            ->assertOk()
            ->assertJsonPath('data.programacion.proxima_fecha', '2026-12-15');
    }

    public function test_desactivar_programado_conserva_los_movimientos(): void
    {
        $movimiento = $this->crear();

        $this->putJson("/api/movimientos/{$movimiento['id']}", $this->datos(['programado' => false]))
            ->assertOk()
            ->assertJsonPath('data.programado', false);

        $this->assertDatabaseCount('programaciones', 0);
        $this->assertSame(1, Movimiento::count());
    }

    public function test_borrar_la_base_pasa_la_programacion_al_mas_reciente(): void
    {
        $base = $this->crear();
        $pago = $this->pagar($base['programacion']['id'])->json('data.id');

        $this->deleteJson("/api/movimientos/{$base['id']}")->assertNoContent();

        $this->assertSame($pago, Programacion::sole()->movimiento_base_id);
    }

    public function test_borrar_el_unico_movimiento_elimina_la_programacion(): void
    {
        $movimiento = $this->crear();

        $this->deleteJson("/api/movimientos/{$movimiento['id']}")->assertNoContent();

        $this->assertDatabaseCount('programaciones', 0);
    }

    public function test_otro_tenant_no_ve_ni_paga_mis_programados(): void
    {
        $programacion = $this->crear(['fecha' => '2026-09-20'])['programacion']['id'];

        Sanctum::actingAs(User::factory()->create());
        $this->pendientes()->assertJsonCount(0, 'data');
        $this->pagar($programacion)->assertNotFound();
        $this->postJson("/api/programaciones/{$programacion}/omitir")->assertNotFound();
    }
}
