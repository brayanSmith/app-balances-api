<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function categoria(string $tipo, string $nombre): Categoria
    {
        return Categoria::where('tipo', $tipo)->where('nombre', $nombre)->sole();
    }

    public function test_cada_tenant_arranca_con_categorias_predeterminadas(): void
    {
        $this->getJson('/api/categorias')
            ->assertOk()
            ->assertJsonCount(count(Categoria::PREDETERMINADAS), 'data')
            ->assertJsonFragment(['nombre' => 'Servicios', 'icono' => 'flash', 'color' => '#EAB308']);
    }

    public function test_crear_editar_y_eliminar_una_categoria(): void
    {
        $id = $this->postJson('/api/categorias', [
            'tipo' => 'gasto',
            'nombre' => 'Mascotas',
            'icono' => 'paw',
            'color' => '#A855F7',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/categorias/{$id}", [
            'tipo' => 'gasto',
            'nombre' => 'Mascotas',
            'icono' => 'paw',
            'color' => '#22C55E',
        ])->assertOk()->assertJsonPath('data.color', '#22C55E');

        $this->deleteJson("/api/categorias/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('categorias', ['id' => $id]);
    }

    public function test_valida_nombre_repetido_icono_y_color(): void
    {
        $this->postJson('/api/categorias', [
            'tipo' => 'gasto',
            'nombre' => 'Comida',
            'icono' => 'NO VALIDO',
            'color' => 'rojo',
        ])->assertUnprocessable()->assertJsonValidationErrors(['nombre', 'icono', 'color']);
    }

    public function test_el_movimiento_devuelve_su_categoria_con_icono_y_color(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto',
            'monto' => 30000,
            'descripcion' => 'Almuerzo',
            'categoria_id' => $this->categoria('gasto', 'Comida')->id,
            'fecha' => '2026-10-20',
        ])->assertCreated()
            ->assertJsonPath('data.categoria.nombre', 'Comida')
            ->assertJsonPath('data.categoria.icono', 'fast-food');
    }

    public function test_no_acepta_una_categoria_de_otro_tipo(): void
    {
        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto',
            'monto' => 30000,
            'descripcion' => 'Almuerzo',
            'categoria_id' => $this->categoria('ingreso', 'Salario')->id,
            'fecha' => '2026-10-20',
        ])->assertUnprocessable()->assertJsonValidationErrors('categoria_id');
    }

    public function test_no_acepta_una_categoria_de_otro_tenant(): void
    {
        $otro = User::factory()->create();
        $ajena = Categoria::withoutGlobalScopes()->where('tenant_id', $otro->tenant_id)->first();

        $this->postJson('/api/movimientos', [
            'tipo' => 'gasto',
            'monto' => 30000,
            'descripcion' => 'Almuerzo',
            'categoria_id' => $ajena->id,
            'fecha' => '2026-10-20',
        ])->assertUnprocessable()->assertJsonValidationErrors('categoria_id');
    }

    public function test_el_resumen_agrupa_los_gastos_por_categoria(): void
    {
        $comida = $this->categoria('gasto', 'Comida')->id;
        foreach ([[20000, $comida], [10000, $comida], [50000, null]] as [$monto, $categoria]) {
            $this->postJson('/api/movimientos', [
                'tipo' => 'gasto',
                'monto' => $monto,
                'descripcion' => 'Gasto',
                'categoria_id' => $categoria,
                'fecha' => '2026-10-20',
            ])->assertCreated();
        }

        $this->getJson('/api/movimientos/resumen?mes=2026-10')
            ->assertOk()
            ->assertJsonPath('gastos', 80000)
            ->assertJsonPath('gastos_por_categoria.0.nombre', 'Sin categoría')
            ->assertJsonPath('gastos_por_categoria.0.total', 50000)
            ->assertJsonPath('gastos_por_categoria.1.nombre', 'Comida')
            ->assertJsonPath('gastos_por_categoria.1.total', 30000);
    }

    public function test_eliminar_la_categoria_conserva_los_movimientos(): void
    {
        $comida = $this->categoria('gasto', 'Comida');
        $movimiento = $this->postJson('/api/movimientos', [
            'tipo' => 'gasto',
            'monto' => 30000,
            'descripcion' => 'Almuerzo',
            'categoria_id' => $comida->id,
            'fecha' => '2026-10-20',
        ])->json('data.id');

        $this->deleteJson("/api/categorias/{$comida->id}")->assertNoContent();

        $this->assertDatabaseHas('movimientos', ['id' => $movimiento, 'categoria_id' => null]);
    }

    public function test_otro_tenant_no_ve_mis_categorias(): void
    {
        $mia = $this->categoria('gasto', 'Comida');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/categorias/{$mia->id}")->assertNotFound();
        $this->deleteJson("/api/categorias/{$mia->id}")->assertNotFound();
    }
}
