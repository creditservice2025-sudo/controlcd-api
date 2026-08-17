<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Conteo de colocación del reporte de resumen general.
 *
 * Las tres columnas nuevas se DERIVAN de la historia de cada cliente, no de un
 * flag: `credits.renewed_from_id` está poblado en 6 de 131.922 créditos, así
 * que leerlo daría cero en todas las rutas. Por eso lo que se prueba acá es la
 * clasificación misma, con el caso que la hace no trivial: un cliente cuyo
 * crédito anterior se liquidó ANTES del período no es una renovación del
 * período, aunque hoy figure como liquidado igual que el que sí lo es.
 */
class ReportCreditCountsTest extends TestCase
{
    use DatabaseTransactions;

    private const DESDE = '2026-08-01';
    private const HASTA = '2026-08-17';
    private const DIA = '2026-08-05';

    private Seller $seller;
    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        if (!DB::table('roles')->where('id', 2)->exists()) {
            DB::table('roles')->insert([
                'id' => 2,
                'name' => 'Role-2-' . uniqid(),
                'guard_name' => 'web',
                'is_assignable' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $admin->id]);
        $country = Country::factory()->create(['name' => 'Perú-' . uniqid(), 'currency' => 'PEN']);
        $this->city = City::factory()->create(['country_id' => $country->id]);
        $this->seller = Seller::factory()->create([
            'company_id' => $company->id,
            'city_id' => $this->city->id,
        ]);
    }

    private function makeClient(): Client
    {
        return Client::factory()->create([
            'seller_id' => $this->seller->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);
    }

    private function makeCredit(Client $client, string $day, string $status = 'Vigente', array $extra = []): Credit
    {
        return Credit::factory()->create(array_merge([
            'client_id' => $client->id,
            'seller_id' => $this->seller->id,
            'status' => $status,
            // CreditFactory manda 'diario' y el enum de la tabla es 'Diaria':
            // sin esto ni siquiera se puede insertar un crédito de prueba.
            'payment_frequency' => 'Diaria',
            'business_date' => $day,
            'created_at' => $day . ' 10:00:00',
            'updated_at' => $day . ' 10:00:00',
        ], $extra));
    }

    private function makePayment(Credit $credit, string $day): void
    {
        DB::table('payments')->insert([
            'credit_id' => $credit->id,
            'payment_date' => $day,
            'business_date' => $day,
            'amount' => 10,
            'status' => 'Pagado',
            'payment_method' => 'Efectivo',
            'created_at' => $day . ' 09:00:00',
            'updated_at' => $day . ' 09:00:00',
        ]);
    }

    /**
     * Un escenario con los cuatro casos que se pueden dar el mismo día, más el
     * ruido que no debe contarse (crédito borrado y crédito fuera del rango).
     */
    private function escenarioCompleto(): void
    {
        // A: su primer crédito -> nuevo
        $this->makeCredit($this->makeClient(), self::DIA);

        // B: ya tenía uno abierto -> existente
        $b = $this->makeClient();
        $this->makeCredit($b, '2026-06-01', 'Vigente');
        $this->makeCredit($b, self::DIA);

        // C: liquidó DENTRO del período y se le activó otro -> renovación
        $c = $this->makeClient();
        $viejoC = $this->makeCredit($c, '2026-06-01', 'Liquidado');
        $this->makePayment($viejoC, '2026-08-03');
        $this->makeCredit($c, self::DIA);

        // D: liquidó ANTES del período -> existente, no renovación del período
        $d = $this->makeClient();
        $viejoD = $this->makeCredit($d, '2026-06-01', 'Liquidado');
        $this->makePayment($viejoD, '2026-07-20');
        $this->makeCredit($d, self::DIA);

        // Ruido: crédito borrado dentro del rango y crédito fuera del rango.
        $this->makeCredit($this->makeClient(), self::DIA, 'Vigente', ['deleted_at' => now()]);
        $this->makeCredit($this->makeClient(), '2026-08-25');
    }

    /** @test */
    public function clasifica_nuevos_existentes_y_renovaciones_del_periodo(): void
    {
        $this->escenarioCompleto();

        $counts = app(LiquidationService::class)
            ->getCreditCountsByGroup(self::DESDE, self::HASTA, 'seller', null, null, null, $this->seller->id);

        $this->assertSame(1, $counts[$this->seller->id]['new_clients'],
            'Nuevo es el cliente cuyo primer crédito cae en el período.');
        $this->assertSame(2, $counts[$this->seller->id]['existing_clients'],
            'Existentes: el que ya tenía uno abierto y el que había liquidado ANTES del período.');
        $this->assertSame(1, $counts[$this->seller->id]['renewed_clients'],
            'Renovación: liquidó dentro del período y se le activó otro después.');
        $this->assertSame(4, $counts[$this->seller->id]['credits_granted'],
            'El crédito borrado y el de fuera del rango no entran.');
    }

    /**
     * El caso que separa "renovación" de "existente" es el DÍA en que se cerró
     * el crédito anterior. Si el último pago del viejo cae dentro del período,
     * es renovación; si cayó antes, el cliente simplemente volvió.
     *
     * @test
     */
    public function no_es_renovacion_si_el_credito_anterior_se_cerro_antes_del_periodo(): void
    {
        $cliente = $this->makeClient();
        $viejo = $this->makeCredit($cliente, '2026-06-01', 'Liquidado');
        $this->makePayment($viejo, '2026-07-20');
        $this->makeCredit($cliente, self::DIA);

        $svc = app(LiquidationService::class);

        $fuera = $svc->getCreditCountsByGroup(self::DESDE, self::HASTA, 'seller', null, null, null, $this->seller->id);
        $this->assertSame(0, $fuera[$this->seller->id]['renewed_clients']);
        $this->assertSame(1, $fuera[$this->seller->id]['existing_clients']);

        // El mismo cliente, con el período abierto hasta incluir el cierre del
        // crédito viejo: ahí sí es una renovación ocurrida dentro de la ventana.
        $dentro = $svc->getCreditCountsByGroup('2026-07-01', self::HASTA, 'seller', null, null, null, $this->seller->id);
        $this->assertSame(1, $dentro[$this->seller->id]['renewed_clients']);
        $this->assertSame(0, $dentro[$this->seller->id]['existing_clients']);
    }

    /**
     * Un pago posterior al crédito nuevo no cierra nada "antes": el crédito
     * viejo seguía vivo ese día, así que el cliente es existente.
     *
     * @test
     */
    public function el_pago_posterior_al_credito_nuevo_no_cuenta_como_cierre_previo(): void
    {
        $cliente = $this->makeClient();
        $viejo = $this->makeCredit($cliente, '2026-06-01', 'Liquidado');
        $this->makePayment($viejo, '2026-08-10'); // después del crédito nuevo
        $this->makeCredit($cliente, self::DIA);

        $counts = app(LiquidationService::class)
            ->getCreditCountsByGroup(self::DESDE, self::HASTA, 'seller', null, null, null, $this->seller->id);

        $this->assertSame(0, $counts[$this->seller->id]['renewed_clients']);
        $this->assertSame(1, $counts[$this->seller->id]['existing_clients']);
    }

    /**
     * Se cuentan CLIENTES, no créditos: dos créditos del mismo cliente en el
     * período son un cliente, aunque credits_granted sí diga dos.
     *
     * @test
     */
    public function un_cliente_con_dos_creditos_en_el_periodo_cuenta_una_vez(): void
    {
        $cliente = $this->makeClient();
        $this->makeCredit($cliente, self::DIA);
        $this->makeCredit($cliente, '2026-08-08');

        $counts = app(LiquidationService::class)
            ->getCreditCountsByGroup(self::DESDE, self::HASTA, 'seller', null, null, null, $this->seller->id);

        $this->assertSame(1, $counts[$this->seller->id]['new_clients']);
        $this->assertSame(1, $counts[$this->seller->id]['existing_clients'],
            'El segundo crédito ya lo encuentra con historia.');
        $this->assertSame(2, $counts[$this->seller->id]['credits_granted']);
    }

    /** @test */
    public function agrupa_por_dia_y_por_ciudad_con_los_mismos_totales(): void
    {
        $this->escenarioCompleto();

        $svc = app(LiquidationService::class);

        $porDia = $svc->getCreditCountsByGroup(self::DESDE, self::HASTA, 'day', null, null, null, $this->seller->id);
        $this->assertArrayHasKey(self::DIA, $porDia, 'El día se indexa por el día de negocio del crédito.');
        $this->assertSame(4, $porDia[self::DIA]['credits_granted']);

        $porCiudad = $svc->getCreditCountsByGroup(self::DESDE, self::HASTA, 'city', null, null, $this->city->id);
        $this->assertSame(1, $porCiudad[$this->city->id]['new_clients']);
        $this->assertSame(1, $porCiudad[$this->city->id]['renewed_clients']);
    }

    /**
     * El reporte por ciudad tiene que traer la moneda del país de la ruta: la
     * tabla mezcla Perú, Colombia, Bolivia y Argentina, y antes todas las cifras
     * se mostraban con un "$" fijo.
     *
     * @test
     */
    public function el_resumen_por_ciudad_trae_la_moneda_y_los_conteos(): void
    {
        $this->escenarioCompleto();

        DB::table('liquidations')->insert([
            'date' => self::DIA,
            'seller_id' => $this->seller->id,
            'currency' => 'PEN',
            'status' => 'approved',
            'collection_target' => 0,
            'initial_cash' => 100,
            'total_collected' => 500,
            'total_expenses' => 0,
            'new_credits' => 0,
            'base_delivered' => 0,
            'real_to_deliver' => 0,
            'shortage' => 0,
            'surplus' => 0,
            'cash_delivered' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fila = app(LiquidationService::class)
            ->getAccumulatedByCity(self::DESDE, self::HASTA)
            ->firstWhere('city_id', $this->city->id);

        $this->assertNotNull($fila, 'La ciudad con liquidación aprobada tiene que aparecer.');
        $this->assertSame('PEN', $fila->currency);
        $this->assertSame(1, $fila->new_clients);
        $this->assertSame(2, $fila->existing_clients);
        $this->assertSame(1, $fila->renewed_clients);
    }
}
