<?php

namespace Tests\Feature\Lifecycle;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Barrido del mismo defecto en los endpoints que quedaban: resolver el vendedor
 * desde `$user->seller`, que para el Supervisor (rol 6) es null porque él no es
 * un vendedor — consulta la ruta de otro.
 *
 * Los tres casos que sobrevivían al barrido, cada uno con su síntoma distinto:
 *   · /payments/daily-totals  → el rol 6 ni figura entre los permitidos: 403.
 *   · /clients/total          → cuenta los clientes de TODO el sistema.
 *   · /credits/clients        → devuelve créditos de rutas ajenas.
 *
 * Los dos últimos son los peligrosos: no fallan, contestan de más. Nadie se
 * entera hasta que alguien decide con un número que no era el suyo.
 */
class SupervisorDataScopeSweepTest extends TestCase
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

    /** Una ruta con un cliente y un crédito, para poder distinguirla de la otra. */
    private function crearRutaConCartera(City $city, string $nombreCliente): Seller
    {
        $seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => User::factory()->create(['role_id' => 5])->id,
        ]);

        $client = Client::factory()->create([
            'seller_id'   => $seller->id,
            'name'        => $nombreCliente,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        Credit::factory()->create([
            'seller_id'           => $seller->id,
            'client_id'           => $client->id,
            'credit_value'        => 1000,
            'total_interest'      => 20,
            'total_amount'        => 1200,
            'number_installments' => 4,
            'payment_frequency'   => 'Semanal',
            'status'              => 'Vigente',
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

        $this->rutaPropia = $this->crearRutaConCartera($city, 'Cliente de la ruta supervisada');
        $this->rutaAjena  = $this->crearRutaConCartera($city, 'Cliente de otra ruta');

        $this->supervisor = User::factory()->create(['role_id' => 6]);
        UserRoute::create([
            'user_id'   => $this->supervisor->id,
            'seller_id' => $this->rutaPropia->id,
        ]);
    }

    private function comoSupervisor(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->supervisor, 'api')
            ->withHeaders([
                'X-Client-Type'      => 'mobile',
                'X-Active-Seller-Id' => (string) $this->rutaPropia->id,
            ])
            ->getJson($url);
    }

    /**
     * Lo llama la pantalla de Liquidaciones del APK cuando el día todavía no
     * tiene liquidación. Con el rol 6 fuera de la lista de permitidos, el
     * supervisor recibía un 403 seco.
     */
    public function test_los_totales_del_dia_responden_al_supervisor(): void
    {
        $hoy = \Carbon\Carbon::now('America/Lima')->toDateString();

        $this->comoSupervisor('/api/payments/daily-totals?date=' . $hoy . '&timezone=America/Lima')
            ->assertOk();
    }

    /** El contador de clientes tiene que ser el de SU ruta, no el del sistema. */
    public function test_el_total_de_clientes_es_el_de_la_ruta_consultada(): void
    {
        $respuesta = $this->comoSupervisor('/api/clients/total');

        $respuesta->assertOk();
        $this->assertSame(
            1,
            (int) $respuesta->json('data'),
            'Debe contar solo el cliente de su ruta, no los de todas'
        );
    }

    /** Y los créditos, igual: solo los de las rutas que tiene asignadas. */
    public function test_los_creditos_por_cliente_son_los_de_la_ruta_consultada(): void
    {
        $respuesta = $this->comoSupervisor('/api/credits/clients');

        $respuesta->assertOk();

        $sellerIds = collect($respuesta->json('data.data') ?? [])
            ->pluck('seller_id')
            ->unique()
            ->values();

        $this->assertTrue(
            $sellerIds->diff([$this->rutaPropia->id])->isEmpty(),
            'No deben venir créditos de rutas ajenas. Vinieron: ' . $sellerIds->implode(', ')
        );
    }
}
