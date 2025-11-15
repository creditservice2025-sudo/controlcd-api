<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Ruta;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class RutaControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_list_rutas()
    {
        // Crea algunas rutas de prueba
        Ruta::factory()->count(3)->create();

        // Realiza la solicitud al controlador
        $response = $this->getJson('/api/rutas');

        // Verifica la respuesta
        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    /** @test */
    public function it_can_show_a_ruta()
    {
        // Crea una ruta de prueba
        $ruta = Ruta::factory()->create();

        // Realiza la solicitud al controlador
        $response = $this->getJson("/api/rutas/{$ruta->id}");

        // Verifica la respuesta
        $response->assertStatus(200);
        $response->assertJson(['id' => $ruta->id]);
    }

    /** @test */
    public function it_can_create_a_ruta()
    {
        // Datos de prueba
        $data = [
            'active' => true,
            'name' => 'Ruta de prueba',
            'sector' => 'Sector de prueba',
            'balance' => 1000.0,
            'credits' => []
        ];

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/rutas', $data);

        // Verifica la respuesta
        $response->assertStatus(201);
        $this->assertDatabaseHas('rutas', ['name' => 'Ruta de prueba']);
    }

    /** @test */
    public function it_can_update_a_ruta()
    {
        // Crea una ruta de prueba
        $ruta = Ruta::factory()->create();

        // Nuevos datos de prueba
        $data = [
            'name' => 'Ruta actualizada',
        ];

        // Realiza la solicitud al controlador
        $response = $this->putJson("/api/rutas/{$ruta->id}", $data);

        // Verifica la respuesta
        $response->assertStatus(200);
        $this->assertDatabaseHas('rutas', ['name' => 'Ruta actualizada']);
    }

    /** @test */
    public function it_can_delete_a_ruta()
    {
        // Crea una ruta de prueba
        $ruta = Ruta::factory()->create();

        // Realiza la solicitud al controlador
        $response = $this->deleteJson("/api/rutas/{$ruta->id}");

        // Verifica la respuesta
        $response->assertStatus(200);
        $this->assertDatabaseMissing('rutas', ['id' => $ruta->id]);
    }

    /** @test */
    public function it_can_bulk_delete_rutas()
    {
        // Crea algunas rutas de prueba
        $rutas = Ruta::factory()->count(3)->create();

        // Ids de las rutas a eliminar
        $ids = $rutas->pluck('id')->toArray();

        // Realiza la solicitud al controlador
        $response = $this->postJson('/api/rutas/bulk-delete', ['ids' => $ids]);

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('rutas', ['id' => $id]);
        }
    }

    /** @test */
    public function it_can_assign_members_to_ruta()
    {
        // Crea una ruta y miembros de prueba
        $ruta = Ruta::factory()->create();
        $members = Member::factory()->count(3)->create();

        // Ids de los miembros a asignar
        $memberIds = $members->pluck('id')->toArray();

        // Realiza la solicitud al controlador
        $response = $this->postJson("/api/rutas/{$ruta->id}/assign-members", ['member_ids' => $memberIds]);

        // Verifica la respuesta
        $response->assertStatus(200);
        foreach ($memberIds as $memberId) {
            $this->assertDatabaseHas('member_ruta', ['ruta_id' => $ruta->id, 'member_id' => $memberId]);
        }
    }
}
