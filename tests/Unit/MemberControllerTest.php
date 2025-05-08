<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Member;
use App\Models\Ruta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class MemberControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_list_members()
    {
        // Crea algunos miembros de prueba
        Member::factory()->count(3)->create();

        // Realiza la solicitud al controlador
        $response = $this->getJson('/api/members');

        // Verifica la respuesta
        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    /** @test */
    public function it_can_show_a_member()
    {
        // Crea un miembro de prueba
        $member = Member::factory()->create();

        // Realiza la solicitud al controlador
        $response = $this->getJson("/api/members/{$member->id}");

        // Verifica la respuesta
        $response->assertStatus(200);
        $response->assertJson(['id' => $member->id]);
    }

    /** @test */
    public function it_can_create_a_member()
    {
        // Datos de prueba
        $data = [
            'active' => true,
            'name' => 'John Doe',
            'identification' => '123456789',
            'role' => 'Admin',
            'department' => 'IT',
            'address' => '123 Main St',
            'city' => 'Metropolis',
            'email' => 'john.doe@example.com',
            'phone' => '555-1234',
            'rutas' => []
        ];

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/members', $data);

        // Verifica la respuesta
        $response->assertStatus(201);
        $this->assertDatabaseHas('members', ['email' => 'john.doe@example.com']);
    }

    /** @test */
    public function it_can_update_a_member()
    {
        // Crea un miembro de prueba
        $member = Member::factory()->create();

        // Nuevos datos de prueba
        $data = [
            'name' => 'Jane Doe',
        ];

        // Realiza la solicitud al controlador
        $response = $this->putJson("/api/members/{$member->id}", $data);

        // Verifica la respuesta
        $response->assertStatus(200);
        $this->assertDatabaseHas('members', ['name' => 'Jane Doe']);
    }

    /** @test */
    public function it_can_delete_a_member()
    {
        // Crea un miembro de prueba
        $member = Member::factory()->create();

        // Realiza la solicitud al controlador
        $response = $this->deleteJson("/api/members/{$member->id}");

        // Verifica la respuesta
        $response->assertStatus(200);
        $this->assertDatabaseMissing('members', ['id' => $member->id]);
    }

    /** @test */
    public function it_can_bulk_delete_members()
    {
        // Crea algunos miembros de prueba
        $members = Member::factory()->count(3)->create();

        // Ids de los miembros a eliminar
        $ids = $members->pluck('id')->toArray();

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/members/bulk-delete', ['ids' => $ids]);

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('members', ['id' => $id]);
        }
    }

    /** @test */
    public function it_can_assign_rutas_to_member()
    {
        // Crea un miembro y rutas de prueba
        $member = Member::factory()->create();
        $rutas = Ruta::factory()->count(3)->create();

        // Ids de las rutas a asignar
        $rutaIds = $rutas->pluck('id')->toArray();

        // Realiza la solicitud al controlador
        $response = $this->postJson("/api/members/{$member->id}/assign-rutas", ['rutas' => $rutaIds]);

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($rutaIds as $rutaId) {
            $this->assertDatabaseHas('member_ruta', ['member_id' => $member->id, 'ruta_id' => $rutaId]);
        }
    }
}
