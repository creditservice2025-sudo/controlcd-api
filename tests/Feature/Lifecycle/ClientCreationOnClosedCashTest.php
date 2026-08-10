<?php

namespace Tests\Feature\Lifecycle;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserRoute;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EL ALTA DE CLIENTE ES TAMBIÉN UN ALTA DE CRÉDITO.
 *
 * `POST /clients/create` exige credit_value, interest_rate, installment_count y
 * payment_frequency: el cliente nace con su crédito inicial, dentro de la misma
 * transacción. Es decir, por esa puerta sale plata de la caja.
 *
 * Pero los cerrojos de crédito viven en CreditController/CreditService, no acá:
 * ClientController no tenía el middleware de caja cerrada y ClientService ni
 * siquiera usaba EnforcesCashOpen. Con la caja del día ya aprobada, un
 * supervisor colocó un crédito de S/ 300 y la liquidación quedó con
 * new_credits = 0: la plata salió y la caja no se enteró.
 *
 * Es el mismo agujero que el ingreso tardío, por otra puerta.
 */
class ClientCreationOnClosedCashTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    private Seller $seller;
    private User $sellerUser;
    private User $supervisor;
    private string $hoy;

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

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2, 5, 6] as $rol) {
            $this->ensureRole($rol);
        }

        $country = Country::factory()->create(['name' => 'Perú', 'timezone' => self::TZ]);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->sellerUser = User::factory()->create(['role_id' => 5]);
        $this->seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => $this->sellerUser->id,
        ]);

        $this->supervisor = User::factory()->create(['role_id' => 6]);
        UserRoute::create([
            'user_id'   => $this->supervisor->id,
            'seller_id' => $this->seller->id,
        ]);

        $this->hoy = \Carbon\Carbon::now(self::TZ)->toDateString();
    }

    private function cerrarCaja(string $estado = 'approved'): Liquidation
    {
        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($this->seller->id, $this->hoy, self::TZ);
        $liq->update(['status' => $estado]);

        // El observer revoca la sesión del cobrador al cerrar; se suelta para
        // poder medir el guard del servicio y no el candado de sesión.
        Cache::forget(\App\Services\LoginService::liquidationClosedKey($this->sellerUser->id));

        return $liq;
    }

    /** Payload mínimo que acepta ClientRequest: cliente + su crédito inicial. */
    private function clienteConCredito(): array
    {
        return [
            'name'                        => 'Cliente sobre caja cerrada',
            'address'                     => 'Calle falsa 123',
            'reference'                   => 'Frente a la plaza',
            'dni'                         => '90909090',
            'phone'                       => '999111222',
            'company_name'                => 'Bodega Test',
            'seller_id'                   => $this->seller->id,
            'routing_order'               => 1,
            'credit_value'                => 300,
            'interest_rate'               => 20,
            'installment_count'           => 24,
            'payment_frequency'           => 'Diaria',
            'micro_insurance_percentage'  => 0,
            'geolocation'                 => ['latitude' => -12.046374, 'longitude' => -77.042793],
            // El alta exige exactamente las 4 fotos obligatorias.
            'images'                      => [
                ['file' => UploadedFile::fake()->image('perfil.jpg'), 'type' => 'profile'],
                ['file' => UploadedFile::fake()->image('dinero.jpg'), 'type' => 'money_in_hand'],
                ['file' => UploadedFile::fake()->image('negocio.jpg'), 'type' => 'business'],
                ['file' => UploadedFile::fake()->image('documento.jpg'), 'type' => 'document'],
            ],
        ];
    }

    /**
     * multipart y no postJson: el payload lleva un archivo (images.*.file es
     * `required|image`) y un JSON no puede transportarlo — la validación
     * rebotaba con 422 antes de llegar a ninguna regla de caja, que fue lo que
     * hizo pasar estos tests por el motivo equivocado en la primera corrida.
     */
    private function crearComo(User $actor, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor, 'api')
            ->withHeaders($headers + ['Accept' => 'application/json'])
            ->post('/api/clients/create', $this->clienteConCredito());
    }

    public function test_el_supervisor_no_da_de_alta_un_cliente_con_credito_sobre_caja_cerrada(): void
    {
        $this->cerrarCaja();

        $respuesta = $this->crearComo($this->supervisor, [
            'X-Client-Type'      => 'mobile',
            'X-Active-Seller-Id' => (string) $this->seller->id,
        ]);

        $this->assertContains(
            $respuesta->status(),
            [403, 422],
            'Con la caja cerrada el alta debe rechazarse. Respondió ' . $respuesta->status()
        );

        $this->assertDatabaseCount('credits', 0);
        $this->assertDatabaseMissing('clients', ['name' => 'Cliente sobre caja cerrada']);
    }

    public function test_el_cobrador_tampoco_da_de_alta_sobre_caja_cerrada(): void
    {
        $this->cerrarCaja('pending');

        $respuesta = $this->crearComo($this->sellerUser, ['X-Client-Type' => 'mobile']);

        $this->assertContains($respuesta->status(), [403, 422]);
        $this->assertDatabaseCount('credits', 0);
    }

    // ══ EL DATO QUE USA EL APK PARA APAGAR LOS BOTONES ═════════════════════

    public function test_el_estado_de_caja_avisa_cuando_esta_cerrada(): void
    {
        $this->cerrarCaja();

        $this->actingAs($this->supervisor, 'api')
            ->withHeaders([
                'X-Client-Type'      => 'mobile',
                'X-Active-Seller-Id' => (string) $this->seller->id,
            ])
            ->getJson('/api/liquidations/seller/' . $this->seller->id . '/cash-status')
            ->assertOk()
            ->assertJsonPath('closed', true)
            ->assertJsonPath('status', 'approved');
    }

    public function test_el_estado_de_caja_avisa_cuando_esta_abierta(): void
    {
        app(LiquidationService::class)
            ->getOrCreateLiquidation($this->seller->id, $this->hoy, self::TZ);

        $this->actingAs($this->sellerUser, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->getJson('/api/liquidations/seller/' . $this->seller->id . '/cash-status')
            ->assertOk()
            ->assertJsonPath('closed', false);
    }

    /** No es un dato público: se responde solo por las rutas propias. */
    public function test_el_estado_de_caja_de_una_ruta_ajena_no_se_responde(): void
    {
        $otroSeller = Seller::factory()->create([
            'city_id' => $this->seller->city_id,
            'user_id' => User::factory()->create(['role_id' => 5])->id,
        ]);

        $this->actingAs($this->supervisor, 'api')
            ->withHeaders([
                'X-Client-Type'      => 'mobile',
                'X-Active-Seller-Id' => (string) $this->seller->id,
            ])
            ->getJson('/api/liquidations/seller/' . $otroSeller->id . '/cash-status')
            ->assertStatus(403);
    }

    /**
     * Lo que NO puede pasar es que el cerrojo tape la operación normal: con la
     * caja abierta el alta tiene que seguir funcionando.
     */
    public function test_con_la_caja_abierta_el_alta_sigue_funcionando(): void
    {
        app(LiquidationService::class)
            ->getOrCreateLiquidation($this->seller->id, $this->hoy, self::TZ); // 'En curso'

        $respuesta = $this->crearComo($this->sellerUser, ['X-Client-Type' => 'mobile']);

        $respuesta->assertSuccessful();
        $this->assertDatabaseHas('clients', ['name' => 'Cliente sobre caja cerrada']);
    }
}
