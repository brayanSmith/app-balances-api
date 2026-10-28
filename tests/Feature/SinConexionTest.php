<?php

namespace Tests\Feature;

use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La app reenvía lo creado sin conexión con un cliente_id: si el primer envío
 * sí llegó (y se perdió la respuesta), el reenvío no debe duplicar nada.
 */
class SinConexionTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '0f8c2f5e-6a1b-4c3d-9e8f-1a2b3c4d5e6f';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27');
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_reenviar_un_movimiento_no_lo_duplica(): void
    {
        $datos = ['tipo' => 'gasto', 'monto' => 8000, 'descripcion' => 'Café', 'fecha' => '2026-10-27', 'cliente_id' => self::UUID];

        $primero = $this->postJson('/api/movimientos', $datos)->assertCreated()->json('data.id');
        $segundo = $this->postJson('/api/movimientos', $datos)->assertSuccessful()->json('data.id');

        $this->assertSame($primero, $segundo);
        $this->assertSame(1, Movimiento::count());
    }

    public function test_reenviar_un_pago_no_lo_registra_ni_avanza_dos_veces(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 100000, 'descripcion' => 'Agua', 'fecha' => '2026-09-07',
            'programado' => true, 'intervalo' => 1, 'frecuencia' => 'meses',
        ])->assertCreated();
        $programacion = Programacion::sole();
        $pago = ['monto' => 98000, 'fecha' => '2026-10-27', 'cliente_id' => self::UUID];

        $this->postJson("/api/programaciones/{$programacion->id}/pagar", $pago)->assertCreated();
        $this->postJson("/api/programaciones/{$programacion->id}/pagar", $pago)->assertSuccessful();

        $this->assertSame(2, Movimiento::count()); // el original + un solo pago
        $this->assertSame('2026-11-07', $programacion->fresh()->proxima_fecha->toDateString());
    }

    public function test_el_mismo_cliente_id_en_otro_tenant_no_choca(): void
    {
        $datos = ['tipo' => 'gasto', 'monto' => 8000, 'descripcion' => 'Café', 'fecha' => '2026-10-27', 'cliente_id' => self::UUID];
        $this->postJson('/api/movimientos', $datos)->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/movimientos', $datos)->assertCreated();

        $this->assertSame(2, Movimiento::withoutGlobalScopes()->count());
    }

    public function test_cliente_id_debe_ser_un_uuid(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 8000, 'descripcion' => 'Café', 'fecha' => '2026-10-27', 'cliente_id' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
    }
}
