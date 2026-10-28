<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalisisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27'); // martes
        Sanctum::actingAs(User::factory()->create());

        foreach ([
            ['ingreso', 1000000, 'Saldo anterior', '2026-09-05'],
            ['ingreso', 3000000, 'Salario', '2026-10-01'],
            ['gasto', 120000, 'Energía', '2026-10-15'],
            ['gasto', 45000, 'Mercado', '2026-10-25'],
        ] as [$tipo, $monto, $descripcion, $fecha]) {
            $this->postJson('/api/movimientos', compact('tipo', 'monto', 'descripcion', 'fecha'))->assertCreated();
        }
    }

    private function analisis(array $params)
    {
        return $this->getJson('/api/analisis?'.http_build_query($params + [
            'desde' => '2026-10-01', 'hasta' => '2026-10-31', 'agrupacion' => 'mes',
        ]));
    }

    private function programado(string $tipo, float $monto, string $fecha): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => $tipo, 'monto' => $monto, 'descripcion' => 'Programado', 'fecha' => $fecha,
            'programado' => true, 'intervalo' => 1, 'frecuencia' => 'meses',
        ])->assertCreated();
    }

    public function test_velas_del_mes_con_el_saldo_acumulado_y_sin_pasar_de_hoy(): void
    {
        $this->analisis([])
            ->assertOk()
            ->assertJsonPath('saldo_inicial', 1000000)
            ->assertJsonPath('saldo_actual', 3835000)
            ->assertJsonPath('ingresos', 3000000)
            ->assertJsonPath('gastos', 165000)
            ->assertJsonPath('proyeccion', null)
            ->assertJsonCount(1, 'periodos')
            ->assertJsonPath('periodos.0', [
                'desde' => '2026-10-01',
                'hasta' => '2026-10-27',
                'ingresos' => 3000000,
                'gastos' => 165000,
                'apertura' => 1000000,
                'cierre' => 3835000,
                'maximo' => 4000000,
                'minimo' => 1000000,
                'proyectado' => false,
            ]);
    }

    public function test_agrupa_por_quincena(): void
    {
        $periodos = $this->analisis(['agrupacion' => 'quincena'])->json('periodos');

        $this->assertSame(['2026-10-01', '2026-10-16'], array_column($periodos, 'desde'));
        $this->assertEquals(['apertura' => 1000000, 'cierre' => 3880000, 'maximo' => 4000000, 'minimo' => 1000000], array_intersect_key($periodos[0], array_flip(['apertura', 'cierre', 'maximo', 'minimo'])));
        // La segunda abre donde cerró la primera.
        $this->assertEquals(3880000, $periodos[1]['apertura']);
        $this->assertEquals(3835000, $periodos[1]['cierre']);
        $this->assertEquals(3835000, $periodos[1]['minimo']);
    }

    public function test_las_semanas_van_de_lunes_a_domingo(): void
    {
        $periodos = $this->analisis(['agrupacion' => 'semana'])->json('periodos');

        // El 1 de octubre es jueves: la primera semana es parcial.
        $this->assertSame(['2026-10-01', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], array_column($periodos, 'desde'));
        $this->assertSame('2026-10-04', $periodos[0]['hasta']);
    }

    public function test_agrupa_por_anio(): void
    {
        $periodos = $this->analisis(['desde' => '2025-06-01', 'agrupacion' => 'anio'])->json('periodos');

        $this->assertSame(['2025-06-01', '2026-01-01'], array_column($periodos, 'desde'));
        $this->assertEquals(0, $periodos[0]['cierre']);
        $this->assertEquals(3835000, $periodos[1]['cierre']);
    }

    public function test_proyecta_con_los_programados_y_el_promedio_de_lo_demas(): void
    {
        $this->programado('gasto', 1200000, '2026-10-05'); // arriendo: vence 5 nov y 5 dic

        $respuesta = $this->analisis(['proyectar_hasta' => '2026-12'])->assertOk();

        $respuesta->assertJsonPath('saldo_actual', 2635000)
            ->assertJsonPath('proyeccion.hasta', '2026-12-31')
            ->assertJsonPath('proyeccion.dias_base', 27)
            ->assertJsonPath('proyeccion.programados', ['ingresos' => 0, 'gastos' => 2400000, 'vencimientos' => 2]);

        // El promedio no cuenta el arriendo (es programado): 3.000.000 y 165.000 en 27 días.
        $this->assertEquals(['ingresos' => 111111.11, 'gastos' => 6111.11], $respuesta->json('proyeccion.promedio_diario'));
        // 65 días (28 oct – 31 dic) × 105.000 netos − 2.400.000 de arriendo.
        $this->assertEqualsWithDelta(7060000, $respuesta->json('proyeccion.saldo_final'), 1);

        $periodos = collect($respuesta->json('periodos'));
        $this->assertSame(['2026-10-01', '2026-10-28', '2026-11-01', '2026-12-01'], $periodos->pluck('desde')->all());
        $this->assertSame([false, true, true, true], $periodos->pluck('proyectado')->all());
        // La proyección arranca del saldo de hoy.
        $this->assertEquals(2635000, $periodos[1]['apertura']);
        $this->assertEqualsWithDelta(1200000 + 30 * 6111.11, $periodos[2]['gastos'], 1);
    }

    public function test_los_vencidos_se_proyectan_para_manana(): void
    {
        $this->programado('gasto', 50000, '2026-08-10'); // vencido desde el 10 sep

        $respuesta = $this->analisis(['proyectar_hasta' => '2026-11', 'agrupacion' => 'semana']);

        // 10 sep y 10 oct (vencidos, van al 28 oct) y 10 nov.
        $respuesta->assertJsonPath('proyeccion.programados.vencimientos', 3);
        $primeraProyectada = collect($respuesta->json('periodos'))->firstWhere('proyectado', true);
        $this->assertSame('2026-10-28', $primeraProyectada['desde']);
        $this->assertGreaterThanOrEqual(100000, $primeraProyectada['gastos']);
    }

    public function test_no_proyecta_si_el_periodo_termino_antes_de_hoy(): void
    {
        $this->analisis(['hasta' => '2026-10-20', 'proyectar_hasta' => '2026-12'])
            ->assertOk()
            ->assertJsonPath('proyeccion', null)
            ->assertJsonMissing(['proyectado' => true]);
    }

    public function test_valida_los_filtros(): void
    {
        $this->analisis(['agrupacion' => 'dia'])->assertJsonValidationErrors('agrupacion');
        $this->analisis(['proyectar_hasta' => '2026-09'])->assertJsonValidationErrors('proyectar_hasta');
        $this->analisis(['proyectar_hasta' => '2029-01'])->assertJsonValidationErrors('proyectar_hasta');
        $this->analisis(['hasta' => '2026-09-01'])->assertJsonValidationErrors('hasta');
    }

    public function test_solo_cuenta_los_movimientos_de_la_cuenta(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->analisis([])
            ->assertJsonPath('saldo_inicial', 0)
            ->assertJsonPath('saldo_actual', 0)
            ->assertJsonPath('periodos.0.maximo', 0);
    }
}
