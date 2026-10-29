<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Eliminado;
use App\Models\Movimiento;
use App\Models\Programacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/sync: la app guarda todo en su SQLite (WatermelonDB) y sincroniza con el servidor.
 */
class SincronizacionTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMACION = '11111111-1111-4111-8111-111111111111';

    private const MOVIMIENTO = '22222222-2222-4222-8222-222222222222';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27 10:00:00');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_el_primer_pull_trae_todo_como_creado_y_con_uuid(): void
    {
        $categoria = Categoria::where('nombre', 'Comida')->sole();
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 8000, 'descripcion' => 'Café', 'fecha' => '2026-10-27', 'categoria_id' => $categoria->id,
        ])->assertCreated();

        $respuesta = $this->getJson('/api/sync')->assertOk();

        $this->assertSame(now()->getTimestampMs(), $respuesta->json('timestamp'));
        $this->assertCount(14, $respuesta->json('changes.categorias.created'));
        $this->assertSame([], $respuesta->json('changes.movimientos.deleted'));
        $respuesta->assertJsonPath('changes.movimientos.created.0', [
            'id' => Movimiento::sole()->uuid,
            'tipo' => 'gasto',
            'monto' => 8000,
            'descripcion' => 'Café',
            'fecha' => '2026-10-27',
            'fecha_programada' => null,
            'categoria_id' => $categoria->uuid,
            'programacion_id' => null,
            'adjuntos_count' => 0,
            'created_at' => now()->getTimestampMs(),
            'updated_at' => now()->getTimestampMs(),
        ]);
    }

    public function test_el_pull_siguiente_solo_trae_lo_que_cambio_y_lo_borrado(): void
    {
        // Lo cambiado en el mismo segundo del pull se vuelve a enviar (para no perderlo).
        $this->travel(1)->seconds();
        $ultimoPull = $this->getJson('/api/sync')->json('timestamp');
        $this->travel(1)->minutes();

        $comida = Categoria::where('nombre', 'Comida')->sole();
        $comida->update(['color' => '#000000']);
        $ocio = Categoria::where('nombre', 'Ocio')->sole();
        $this->deleteJson("/api/categorias/{$ocio->id}")->assertNoContent();

        $cambios = $this->getJson("/api/sync?last_pulled_at={$ultimoPull}")->assertOk()->json('changes.categorias');

        $this->assertSame([], $cambios['created']);
        $this->assertSame([$comida->uuid], array_column($cambios['updated'], 'id'));
        $this->assertSame([$ocio->uuid], $cambios['deleted']);
    }

    public function test_al_borrar_una_categoria_sus_movimientos_se_vuelven_a_enviar(): void
    {
        $ocio = Categoria::where('nombre', 'Ocio')->sole();
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto', 'monto' => 30000, 'descripcion' => 'Cine', 'fecha' => '2026-10-27', 'categoria_id' => $ocio->id,
        ])->assertCreated();
        $ultimoPull = $this->getJson('/api/sync')->json('timestamp');
        $this->travel(1)->minutes();

        $this->deleteJson("/api/categorias/{$ocio->id}")->assertNoContent();

        $this->getJson("/api/sync?last_pulled_at={$ultimoPull}")
            ->assertJsonPath('changes.movimientos.updated.0.categoria_id', null);
    }

    public function test_el_push_crea_un_programado_con_su_movimiento_base(): void
    {
        $categoria = Categoria::where('nombre', 'Servicios')->sole();

        $this->postJson('/api/sync', ['last_pulled_at' => null, 'changes' => $this->programadoNuevo($categoria->uuid)])
            ->assertNoContent();

        $movimiento = Movimiento::sole();
        $programacion = Programacion::sole();
        $this->assertSame(self::MOVIMIENTO, $movimiento->uuid);
        $this->assertSame($this->user->id, $movimiento->user_id);
        $this->assertSame($categoria->id, $movimiento->categoria_id);
        $this->assertSame($programacion->id, $movimiento->programacion_id);
        $this->assertSame($movimiento->id, $programacion->movimiento_base_id);
        $this->assertSame('2026-11-07', $programacion->proxima_fecha->toDateString());
    }

    public function test_reenviar_el_mismo_push_no_duplica(): void
    {
        $cambios = $this->programadoNuevo(null);

        $this->postJson('/api/sync', ['changes' => $cambios])->assertNoContent();
        $this->postJson('/api/sync', ['changes' => $cambios])->assertNoContent();

        $this->assertSame(1, Movimiento::count());
        $this->assertSame(1, Programacion::count());
    }

    public function test_si_otro_dispositivo_cambio_el_registro_el_push_se_rechaza(): void
    {
        $this->postJson('/api/sync', ['changes' => $this->programadoNuevo(null)])->assertNoContent();
        $ultimoPull = $this->getJson('/api/sync')->json('timestamp');
        $this->travel(1)->minutes();
        Movimiento::sole()->update(['descripcion' => 'Agua (desde otro celular)']);

        $this->postJson('/api/sync', ['last_pulled_at' => $ultimoPull, 'changes' => [
            'movimientos' => ['updated' => [[...$this->movimiento(null), 'descripcion' => 'Agua y alcantarillado']]],
        ]])->assertStatus(409);

        $this->assertSame('Agua (desde otro celular)', Movimiento::sole()->descripcion);
    }

    public function test_el_push_borra_sin_repetir_la_logica_de_programados(): void
    {
        $this->postJson('/api/sync', ['changes' => $this->programadoNuevo(null)])->assertNoContent();

        // La app ya resolvió qué pasa con el programado: lo borra en el mismo envío.
        $this->postJson('/api/sync', ['changes' => [
            'movimientos' => ['deleted' => [self::MOVIMIENTO]],
            'programaciones' => ['deleted' => [self::PROGRAMACION]],
        ]])->assertNoContent();

        $this->assertSame(0, Movimiento::count());
        $this->assertSame(0, Programacion::count());
        $this->assertEqualsCanonicalizing(
            [self::MOVIMIENTO, self::PROGRAMACION],
            Eliminado::pluck('uuid')->all()
        );
    }

    public function test_lo_borrado_no_revive_con_un_push_atrasado(): void
    {
        $this->postJson('/api/sync', ['changes' => $this->programadoNuevo(null)])->assertNoContent();
        $this->postJson('/api/sync', ['changes' => ['movimientos' => ['deleted' => [self::MOVIMIENTO]]]])->assertNoContent();

        $this->postJson('/api/sync', ['changes' => ['movimientos' => ['created' => [$this->movimiento(null)]]]])->assertNoContent();

        $this->assertSame(0, Movimiento::count());
    }

    public function test_el_push_valida_los_datos(): void
    {
        $this->postJson('/api/sync', ['changes' => [
            'movimientos' => ['created' => [[...$this->movimiento(null), 'monto' => -5]]],
        ]])->assertUnprocessable()->assertJsonValidationErrors('monto');

        $this->assertSame(0, Movimiento::count());
    }

    public function test_una_categoria_repetida_se_rechaza_sin_guardar_nada(): void
    {
        $this->postJson('/api/sync', ['changes' => [
            'categorias' => ['created' => [['id' => 'cat-nueva', 'tipo' => 'gasto', 'nombre' => 'Comida', 'icono' => 'pizza', 'color' => '#FF0000']]],
            'movimientos' => ['created' => [$this->movimiento(null)]],
        ]])->assertUnprocessable()->assertJsonValidationErrors('categorias');

        $this->assertSame(0, Movimiento::count());
    }

    public function test_no_trae_ni_toca_datos_de_otro_tenant(): void
    {
        $otro = User::factory()->create();
        Movimiento::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $otro->tenant_id, 'user_id' => $otro->id, 'uuid' => self::MOVIMIENTO,
            'tipo' => 'gasto', 'monto' => 1000, 'descripcion' => 'Ajeno', 'fecha' => '2026-10-27',
        ]);

        $this->assertSame([], $this->getJson('/api/sync')->json('changes.movimientos.created'));

        $this->postJson('/api/sync', ['changes' => ['movimientos' => ['deleted' => [self::MOVIMIENTO]]]])->assertNoContent();
        $this->assertSame(1, Movimiento::withoutGlobalScopes()->count());
    }

    public function test_restablecer_la_cuenta_avisa_los_borrados(): void
    {
        $this->postJson('/api/sync', ['changes' => $this->programadoNuevo(null)])->assertNoContent();
        $ultimoPull = $this->getJson('/api/sync')->json('timestamp');
        $this->travel(1)->minutes();

        $this->postJson('/api/cuenta/restablecer', ['confirmacion' => 'BORRAR', 'password' => 'password'])->assertOk();

        $cambios = $this->getJson("/api/sync?last_pulled_at={$ultimoPull}")->json('changes');
        $this->assertSame([self::MOVIMIENTO], $cambios['movimientos']['deleted']);
        $this->assertSame([self::PROGRAMACION], $cambios['programaciones']['deleted']);
        $this->assertCount(14, $cambios['categorias']['deleted']);
        $this->assertCount(14, $cambios['categorias']['created']); // las predeterminadas, nuevas
    }

    public function test_sincronizar_seguido_no_gasta_el_limite_de_restablecer(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/sync')->assertOk();
        }

        $this->postJson('/api/cuenta/restablecer', ['confirmacion' => 'BORRAR', 'password' => 'password'])->assertOk();
    }

    public function test_los_comprobantes_se_suben_y_listan_por_uuid_y_el_pull_trae_su_cantidad(): void
    {
        Storage::fake('local');
        $this->postJson('/api/sync', ['changes' => $this->programadoNuevo(null)])->assertNoContent();
        $this->travel(1)->seconds();
        $ultimoPull = $this->getJson('/api/sync')->json('timestamp');
        $this->travel(1)->minutes();

        $this->postJson('/api/movimientos/'.self::MOVIMIENTO.'/adjuntos', ['archivo' => UploadedFile::fake()->image('factura.jpg')])
            ->assertCreated();

        $this->getJson('/api/movimientos/'.self::MOVIMIENTO.'/adjuntos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'factura.jpg');
        // El id numérico sigue sirviendo (versiones anteriores de la app).
        $this->getJson('/api/movimientos/'.Movimiento::sole()->id.'/adjuntos')->assertJsonCount(1, 'data');

        $this->getJson("/api/sync?last_pulled_at={$ultimoPull}")
            ->assertJsonPath('changes.movimientos.updated.0.id', self::MOVIMIENTO)
            ->assertJsonPath('changes.movimientos.updated.0.adjuntos_count', 1);
    }

    public function test_no_se_ven_comprobantes_de_otro_tenant_por_uuid(): void
    {
        $otro = User::factory()->create();
        Movimiento::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $otro->tenant_id, 'user_id' => $otro->id, 'uuid' => self::MOVIMIENTO,
            'tipo' => 'gasto', 'monto' => 1000, 'descripcion' => 'Ajeno', 'fecha' => '2026-10-27',
        ]);

        $this->getJson('/api/movimientos/'.self::MOVIMIENTO.'/adjuntos')->assertNotFound();
    }

    private function programadoNuevo(?string $categoria): array
    {
        return [
            'programaciones' => ['created' => [[
                'id' => self::PROGRAMACION, 'movimiento_base_id' => self::MOVIMIENTO, 'intervalo' => 1, 'frecuencia' => 'meses',
                'fecha_inicio' => '2026-10-07', 'fecha_fin' => null, 'ocurrencias' => 1, 'proxima_fecha' => '2026-11-07', 'activo' => true,
            ]]],
            'movimientos' => ['created' => [$this->movimiento($categoria)]],
        ];
    }

    private function movimiento(?string $categoria): array
    {
        return [
            'id' => self::MOVIMIENTO, 'categoria_id' => $categoria, 'programacion_id' => self::PROGRAMACION,
            'tipo' => 'gasto', 'monto' => 95000, 'descripcion' => 'Agua', 'fecha' => '2026-10-07', 'fecha_programada' => null,
        ];
    }
}
