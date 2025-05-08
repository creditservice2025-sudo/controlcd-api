<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Credit;
use App\Models\Member;
use App\Models\Ruta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class CreditControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_list_credits()
    {
        // Crea algunos créditos de prueba
        Credit::factory()->count(3)->create();

        // Realiza la solicitud al controlador
        $response = $this->getJson('/api/credits');

        // Verifica la respuesta
        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    /** @test */
    public function it_can_show_a_credit()
    {
        // Crea un crédito de prueba
        $credit = Credit::factory()->create();

        // Realiza la solicitud al controlador
        $response = $this->getJson("/api/credits/{$credit->id}");

        // Verifica la respuesta
        $response->assertStatus(200);
        $response->assertJson(['id' => $credit->id]);
    }

    /** @test */
    public function it_can_create_a_credit()
    {
        // Datos de prueba
        $member = Member::factory()->create();
        $ruta = Ruta::factory()->create();

        $data = [
            'member_id' => $member->id,
            'route_id' => $ruta->id,
            'total_value' => 1000.0,
            'quota_value' => 100.0,
            'total_quotas' => 10,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-01',
            'first_payment_date' => '2024-02-01',
            'payment_frequency' => 'monthly',
            'payment_day' => 1,
            'credit_counts' => []
        ];

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits', $data);

        // Verifica la respuesta
        $response->assertStatus(201);
        $this->assertDatabaseHas('credits', ['total_value' => 1000.0]);
    }

    /** @test */
    public function it_can_update_a_credit()
    {
        // Crea un crédito de prueba
        $credit = Credit::factory()->create();

        // Nuevos datos de prueba
        $data = [
            'total_value' => 2000.0,
        ];

        // Realiza la solicitud al controlador
        $response = $this->putJson("/api/credits/{$credit->id}", $data);

        // Verifica la respuesta
        $response->assertStatus(200);
        $this->assertDatabaseHas('credits', ['total_value' => 2000.0]);
    }

    /** @test */
    public function it_can_delete_a_credit()
    {
        // Crea un crédito de prueba
        $credit = Credit::factory()->create();

        // Realiza la solicitud al controlador
        $response = $this->deleteJson("/api/credits/{$credit->id}");

        // Verifica la respuesta
        $response->assertStatus(200);
        $this->assertDatabaseMissing('credits', ['id' => $credit->id]);
    }

    /** @test */
    public function it_can_bulk_delete_credits()
    {
        // Crea algunos créditos de prueba
        $credits = Credit::factory()->count(3)->create();

        // Ids de los créditos a eliminar
        $ids = $credits->pluck('id')->toArray();

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits/bulk-delete', ['ids' => $ids]);

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('credits', ['id' => $id]);
        }
    }

    /** @test */
    public function it_can_toggle_active_status_of_credits()
    {
        // Crea algunos créditos de prueba
        $credits = Credit::factory()->count(3)->create(['active' => false]);

        // Ids de los créditos a activar
        $ids = $credits->pluck('id')->toArray();

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits/bulk-toggle', ['ids' => $ids, 'active' => true]);

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertDatabaseHas('credits', ['id' => $id, 'active' => true]);
        }
    }

    /** @test */
    public function it_can_cancel_new_credits_without_payments()
    {
        // Crea algunos créditos de prueba activos sin pagos
        $credits = Credit::factory()->count(3)->create(['active' => true]);

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits/cancel-new-credits');

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($credits as $credit) {
            $this->assertDatabaseHas('credits', ['id' => $credit->id, 'active' => false]);
        }
    }

    /** @test */
    public function it_can_deactivate_members_without_payments()
    {
        // Crea algunos miembros de prueba sin pagos
        $members = Member::factory()->count(3)->create(['active' => true]);

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits/deactivate-members-without-payments');

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($members as $member) {
            $this->assertDatabaseHas('members', ['id' => $member->id, 'active' => false]);
        }
    }

    /** @test */
    public function it_can_reactivate_inactive_members()
    {
        // Crea algunos miembros de prueba inactivos
        $members = Member::factory()->count(3)->create(['active' => false]);

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits/reactivate-inactive-members');

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($members as $member) {
            $this->assertDatabaseHas('members', ['id' => $member->id, 'active' => true]);
        }
    }

    /** @test */
    public function it_can_delete_inactive_members()
    {
        // Crea algunos miembros de prueba inactivos
        $members = Member::factory()->count(3)->create(['active' => false]);

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/credits/delete-inactive-members');

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($members as $member) {
            $this->assertDatabaseMissing('members', ['id' => $member->id]);
        }
    }
}
