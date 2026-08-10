<?php

namespace Tests\Feature\Lifecycle;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Consultar datos" del Supervisor tiene que traer los clientes del VENDEDOR
 * CONSULTADO, y solo esos.
 *
 * getClientsSelect acotaba por rol 5 (su propio vendedor), 11 (sus rutas), 2
 * (su empresa) y 1 (empresa impersonada). Para el rol 6 no había NADA: la
 * consulta salía sin filtro de vendedor y se traía los clientes de todo el
 * sistema —37.288 en la base real—, lo que reventaba la memoria de PHP y
 * llegaba a la pantalla como "Error al cargar la lista de clientes".
 *
 * Además del alcance por ruta activa, se fija que el supervisor no pueda
 * pedir la cartera de una ruta ajena mandando otro seller_id.
 */
class SupervisorClientsScopeTest extends TestCase
{
    use RefreshDatabase;

    private Seller $rutaPropia;
    private Seller $rutaAjena;
    private User $supervisor;

    private function ensureRole(int $id): void
    {
        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id'            => $id,
                'name'          => 'Role-' . $id . '-' . uniqid(),
                'guard_name'    => 'web',
                'is_assignable' => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    private function crearRuta(City $city, string $nombreCliente): Seller
    {
        $this->ensureRole(5);

        $seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => User::factory()->create(['role_id' => 5])->id,
        ]);

        Client::factory()->create([
            'seller_id'   => $seller->id,
            'name'        => $nombreCliente,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        return $seller;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([5, 6] as $rol) {
            $this->ensureRole($rol);
        }

        $country = Country::factory()->create(['name' => 'Perú', 'timezone' => 'America/Lima']);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->rutaPropia = $this->crearRuta($city, 'Cliente de la ruta supervisada');
        $this->rutaAjena  = $this->crearRuta($city, 'Cliente de otra ruta');

        $this->supervisor = User::factory()->create(['role_id' => 6]);
        UserRoute::create([
            'user_id'   => $this->supervisor->id,
            'seller_id' => $this->rutaPropia->id,
        ]);
    }

    private function consultar(?int $rutaActiva, array $query = []): \Illuminate\Testing\TestResponse
    {
        $headers = ['X-Client-Type' => 'mobile'];
        if ($rutaActiva) {
            $headers['X-Active-Seller-Id'] = (string) $rutaActiva;
        }

        return $this->actingAs($this->supervisor, 'api')
            ->withHeaders($headers)
            ->getJson('/api/clients/select?' . http_build_query($query));
    }

    public function test_el_supervisor_solo_ve_los_clientes_de_la_ruta_que_consulta(): void
    {
        $respuesta = $this->consultar($this->rutaPropia->id);

        $respuesta->assertOk();

        $sellerIds = collect($respuesta->json('data'))->pluck('seller_id')->unique()->values();

        $this->assertEquals(
            [$this->rutaPropia->id],
            $sellerIds->all(),
            'Solo deben venir clientes de la ruta activa'
        );
    }

    /**
     * El candado de fondo: aunque mande a mano el seller_id de otra ruta, el
     * filtro por user_routes se aplica igual y no devuelve nada ajeno.
     */
    public function test_el_supervisor_no_puede_pedir_la_cartera_de_una_ruta_ajena(): void
    {
        $respuesta = $this->consultar(
            $this->rutaPropia->id,
            ['seller_id' => $this->rutaAjena->id]
        );

        $ajenos = collect($respuesta->json('data') ?? [])
            ->where('seller_id', $this->rutaAjena->id);

        $this->assertCount(0, $ajenos, 'No debe devolver clientes de una ruta no asignada');
    }

    // ══ MOROSOS (mismo endpoint por vendedor, otra puerta) ═════════════════

    public function test_el_supervisor_consulta_la_morosidad_de_su_ruta(): void
    {
        $this->actingAs($this->supervisor, 'api')
            ->withHeaders([
                'X-Client-Type'      => 'mobile',
                'X-Active-Seller-Id' => (string) $this->rutaPropia->id,
            ])
            ->getJson('/api/clients/seller/' . $this->rutaPropia->id . '/debtor')
            ->assertOk();
    }

    /**
     * Acá el vendedor llega por la URL, no por el header: sin chequeo propio,
     * el supervisor podía leer la morosidad de cualquier ruta cambiando el
     * número. El endpoint validaba los roles 2 y 5, pero no el 6.
     */
    public function test_el_supervisor_no_consulta_la_morosidad_de_una_ruta_ajena(): void
    {
        $this->actingAs($this->supervisor, 'api')
            ->withHeaders([
                'X-Client-Type'      => 'mobile',
                'X-Active-Seller-Id' => (string) $this->rutaPropia->id,
            ])
            ->getJson('/api/clients/seller/' . $this->rutaAjena->id . '/debtor')
            ->assertStatus(403);
    }

    /**
     * Sin ruta activa queda acotado a sus rutas asignadas. Lo que NO puede
     * volver a pasar es que salga sin filtro y arrastre toda la base.
     */
    public function test_sin_ruta_activa_queda_acotado_a_sus_rutas_asignadas(): void
    {
        $respuesta = $this->consultar(null);

        $sellerIds = collect($respuesta->json('data') ?? [])->pluck('seller_id')->unique();

        $this->assertTrue(
            $sellerIds->diff([$this->rutaPropia->id])->isEmpty(),
            'Sin ruta activa solo pueden venir clientes de sus rutas asignadas'
        );
    }
}
