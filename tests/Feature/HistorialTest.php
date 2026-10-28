<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HistorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

        foreach ([
            ['ingreso', 3000000, 'Salario', '2026-10-01'],
            ['gasto', 120000, 'Energía', '2026-10-15'],
            ['gasto', 45000, 'Mercado del barrio', '2026-10-25'],
            ['gasto', 30000, 'Almuerzo', '2026-10-26'],
            ['gasto', 80000, 'Mercado grande', '2025-12-20'],
        ] as [$tipo, $monto, $descripcion, $fecha]) {
            $this->postJson('/api/movimientos', compact('tipo', 'monto', 'descripcion', 'fecha'))->assertCreated();
        }
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->getJson('/api/movimientos?desde=2026-10-15&hasta=2026-10-25')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.descripcion', 'Mercado del barrio');

        $this->getJson('/api/movimientos/resumen?desde=2026-10-01&hasta=2026-10-31')
            ->assertJson(['ingresos' => 3000000, 'gastos' => 195000, 'balance' => 2805000]);
    }

    public function test_un_solo_dia(): void
    {
        $this->getJson('/api/movimientos?desde=2026-10-26&hasta=2026-10-26')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.descripcion', 'Almuerzo');
    }

    public function test_filtra_por_tipo_y_busca_por_descripcion(): void
    {
        $this->getJson('/api/movimientos?tipo=ingreso')->assertJsonCount(1, 'data');
        $this->getJson('/api/movimientos?q=mercado')->assertJsonCount(2, 'data');
        $this->getJson('/api/movimientos/resumen?q=mercado&desde=2026-01-01')->assertJson(['gastos' => 45000]);
    }

    public function test_el_resumen_trae_el_balance_acumulado_dia_a_dia(): void
    {
        $this->travelTo('2026-10-26');

        $serie = $this->getJson('/api/movimientos/resumen?mes=2026-10')->json('balance_diario');

        // Del 1 de octubre hasta hoy (26), un punto por día.
        $this->assertCount(26, $serie);
        $this->assertSame(['fecha' => '2026-10-01', 'balance' => 3000000], $serie[0]);
        $this->assertSame(2880000, $serie[14]['balance']); // 15 oct: -120.000 de Energía
        $this->assertSame(2805000, $serie[25]['balance']); // 26 oct: igual al balance del mes
    }

    public function test_sin_periodo_no_hay_balance_diario(): void
    {
        $this->getJson('/api/movimientos/resumen')->assertJsonPath('balance_diario', []);
    }

    public function test_pagina_si_se_pide(): void
    {
        $this->getJson('/api/movimientos?page=1')
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_valida_que_hasta_no_sea_anterior_a_desde(): void
    {
        $this->getJson('/api/movimientos?desde=2026-10-20&hasta=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hasta');
    }

    public function test_la_evolucion_agrupa_por_dia_en_periodos_cortos(): void
    {
        $this->travelTo('2026-10-27');

        $evolucion = $this->getJson('/api/movimientos/resumen?desde=2026-10-20&hasta=2026-10-27')->json('evolucion');

        $this->assertSame('dia', $evolucion['agrupacion']);
        $this->assertCount(8, $evolucion['puntos']);
        $this->assertEquals(['desde' => '2026-10-25', 'ingresos' => 0, 'gastos' => 45000], $evolucion['puntos'][5]);
        $this->assertEquals(30000, $evolucion['puntos'][6]['gastos']);
    }

    public function test_la_evolucion_agrupa_por_mes_en_un_anio_y_no_pasa_de_hoy(): void
    {
        $this->travelTo('2026-10-27');

        $evolucion = $this->getJson('/api/movimientos/resumen?desde=2025-10-27&hasta=2026-12-31')->json('evolucion');

        $this->assertSame('mes', $evolucion['agrupacion']);
        $puntos = collect($evolucion['puntos']);
        $this->assertSame('2025-10-27', $puntos->first()['desde']); // primer mes parcial
        $this->assertSame('2026-10-01', $puntos->last()['desde']);
        $this->assertCount(13, $puntos);
        $this->assertEquals(80000, $puntos->firstWhere('desde', '2025-12-01')['gastos']);
        $this->assertEquals(['desde' => '2026-10-01', 'ingresos' => 3000000, 'gastos' => 195000], $puntos->last());
    }

    public function test_la_evolucion_agrupa_por_semana_y_respeta_el_tipo(): void
    {
        $this->travelTo('2026-10-27');

        $evolucion = $this->getJson('/api/movimientos/resumen?desde=2026-09-01&hasta=2026-10-27&tipo=gasto')->json('evolucion');

        $this->assertSame('semana', $evolucion['agrupacion']);
        $this->assertSame(['2026-09-01', '2026-09-08'], array_column(array_slice($evolucion['puntos'], 0, 2), 'desde'));
        $this->assertEquals(0, array_sum(array_column($evolucion['puntos'], 'ingresos')));
        $this->assertEquals(195000, array_sum(array_column($evolucion['puntos'], 'gastos')));
    }

    public function test_todo_muestra_todo_y_agrupa_por_mes_desde_el_primer_movimiento(): void
    {
        $this->travelTo('2026-10-27');

        $this->getJson('/api/movimientos?hasta=2026-10-27')->assertJsonCount(5, 'data');

        $resumen = $this->getJson('/api/movimientos/resumen?hasta=2026-10-27&agrupacion=mes')
            ->assertJson(['ingresos' => 3000000, 'gastos' => 275000])
            ->json('evolucion');

        $this->assertSame('mes', $resumen['agrupacion']);
        // El primer movimiento es del 20 dic 2025: de ahí hasta octubre son 11 meses.
        $this->assertSame('2025-12-20', $resumen['puntos'][0]['desde']);
        $this->assertSame('2026-10-01', end($resumen['puntos'])['desde']);
        $this->assertCount(11, $resumen['puntos']);
    }

    public function test_todo_sin_movimientos_no_trae_evolucion(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/movimientos/resumen?hasta=2026-10-27&agrupacion=mes')
            ->assertOk()
            ->assertJsonPath('evolucion', null);
    }

    public function test_valida_la_agrupacion(): void
    {
        $this->getJson('/api/movimientos/resumen?desde=2026-10-01&hasta=2026-10-27&agrupacion=quincena')
            ->assertJsonValidationErrors('agrupacion');
    }
}
