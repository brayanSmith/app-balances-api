<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $movimiento = [
        'tipo' => 'gasto',
        'monto' => 50,
        'descripcion' => 'Mercado',
        'fecha' => '2026-10-20',
    ];

    public function test_registro_crea_un_tenant_para_el_usuario(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'secreto123',
            'tenant_nombre' => 'Casa de Ana',
        ]);

        $response->assertCreated()->assertJsonPath('user.tenant.nombre', 'Casa de Ana');
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'tenant_id' => $response->json('user.tenant.id')]);
    }

    public function test_el_movimiento_se_guarda_con_el_tenant_del_usuario(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/movimientos', $this->movimiento)->assertCreated();

        $this->assertDatabaseHas('movimientos', ['tenant_id' => $user->tenant_id, 'user_id' => $user->id]);
    }

    public function test_usuarios_del_mismo_tenant_comparten_movimientos(): void
    {
        $ana = User::factory()->create();
        $luis = User::factory()->for($ana->tenant)->create();

        Sanctum::actingAs($ana);
        $id = $this->postJson('/api/movimientos', $this->movimiento)->json('data.id');

        Sanctum::actingAs($luis);
        $this->getJson('/api/movimientos')->assertJsonCount(1, 'data');
        $this->getJson("/api/movimientos/{$id}")->assertOk();
    }

    public function test_un_tenant_no_ve_ni_modifica_movimientos_de_otro(): void
    {
        $ana = User::factory()->create();
        $intruso = User::factory()->create();

        Sanctum::actingAs($ana);
        $id = $this->postJson('/api/movimientos', $this->movimiento)->json('data.id');

        Sanctum::actingAs($intruso);
        $this->getJson('/api/movimientos')->assertJsonCount(0, 'data');
        $this->getJson('/api/movimientos/resumen')->assertJson(['gastos' => 0, 'balance' => 0]);
        $this->getJson("/api/movimientos/{$id}")->assertNotFound();
        $this->putJson("/api/movimientos/{$id}", $this->movimiento)->assertNotFound();
        $this->deleteJson("/api/movimientos/{$id}")->assertNotFound();

        $this->assertDatabaseHas('movimientos', ['id' => $id]);
    }
}
