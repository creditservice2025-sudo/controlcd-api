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
 * Las tres categorías las define el negocio, y lo que las separa es el estado
 * del cliente EN EL MOMENTO del crédito nuevo:
 *
 *   nuevo        es su primer crédito (el crédito es obligatorio: un cliente
 *                dado de alta sin crédito no entra en el conteo)
 *   liquidó y
 *   tomó otro    ya tenía historia y no le quedaba ningún crédito abierto
 *   adicional    se le activó otro crédito SIN liquidar el anterior
 *
 * Además del conteo se prueba el detalle que abre el modal, porque un número
 * que no se puede auditar contra su propia lista no sirve para decidir.
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

    private function makeClient(string $nombre = null): Client
    {
        return Client::factory()->create([
            'seller_id' => $this->seller->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
            'name' => $nombre ?? ('Cliente ' . Str::random(6)),
        ]);
    }

    private function makeCredit(Client $client, string $day, string $status = 'Vigente', array $extra = []): Credit
    {
        return Credit::factory()->create(array_merge([
            'client_id' => $client->id,
            'seller_id' => $this->seller->id,
            'status' => $status,
            'business_date' => $day,
            // CreditFactory manda 'diario' y el enum de la tabla es 'Diaria'.
            'payment_frequency' => 'Diaria',
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

    private function counts(string $desde = self::DESDE, string $hasta = self::HASTA): array
    {
        return app(LiquidationService::class)
            ->getCreditCountsByGroup($desde, $hasta, 'seller', null, null, null, $this->seller->id)[$this->seller->id];
    }

    private function detalle(string $bucket, string $desde = self::DESDE, string $hasta = self::HASTA): array
    {
        return app(LiquidationService::class)
            ->getCreditClassificationDetail($desde, $hasta, $bucket, null, null, null, $this->seller->id);
    }

    /**
     * Un cliente de cada categoría, más el ruido que no debe contarse.
     */
    private function escenarioCompleto(): void
    {
        // A: su primer crédito -> nuevo
        $this->makeCredit($this->makeClient('A NUEVO'), self::DIA);

        // B: tenía uno Vigente sin liquidar -> adicional
        $b = $this->makeClient('B ADICIONAL');
        $this->makeCredit($b, '2026-06-01', 'Vigente');
        $this->makeCredit($b, self::DIA);

        // C: liquidó dentro del período y tomó otro -> liquidó y tomó otro
        $c = $this->makeClient('C LIQUIDO DENTRO');
        $viejoC = $this->makeCredit($c, '2026-06-01', 'Liquidado');
        $this->makePayment($viejoC, '2026-08-03');
        $this->makeCredit($c, self::DIA);

        // D: liquidó ANTES del período. Tampoco tenía nada abierto el día del
        // crédito nuevo, así que es la misma categoría que C.
        $d = $this->makeClient('D LIQUIDO ANTES');
        $viejoD = $this->makeCredit($d, '2026-06-01', 'Liquidado');
        $this->makePayment($viejoD, '2026-07-20');
        $this->makeCredit($d, self::DIA);

        // Ruido: crédito borrado dentro del rango y crédito fuera del rango.
        $this->makeCredit($this->makeClient('BORRADO'), self::DIA, 'Vigente', ['deleted_at' => now()]);
        $this->makeCredit($this->makeClient('FUERA'), '2026-08-25');
    }

    /** @test */
    public function clasifica_las_tres_categorias_del_negocio(): void
    {
        $this->escenarioCompleto();

        $counts = $this->counts();

        $this->assertSame(1, $counts['new_clients'],
            'Nuevo es el cliente cuyo primer crédito cae en el período.');
        $this->assertSame(2, $counts['settled_clients'],
            'Liquidó y tomó otro: no le quedaba ninguno abierto, sin importar cuándo cerró.');
        $this->assertSame(1, $counts['additional_clients'],
            'Adicional: se le activó otro crédito sin liquidar el anterior.');
        $this->assertSame(4, $counts['credits_granted'],
            'El crédito borrado y el de fuera del rango no entran.');
    }

    /**
     * Lo que separa "adicional" de "liquidó y tomó otro" es si el crédito
     * anterior seguía vivo ESE día, no la fecha en que se cerró.
     *
     * @test
     */
    public function haber_liquidado_antes_del_periodo_no_lo_hace_adicional(): void
    {
        $cliente = $this->makeClient();
        $viejo = $this->makeCredit($cliente, '2026-06-01', 'Liquidado');
        $this->makePayment($viejo, '2026-07-20');
        $this->makeCredit($cliente, self::DIA);

        $counts = $this->counts();

        $this->assertSame(1, $counts['settled_clients']);
        $this->assertSame(0, $counts['additional_clients']);
    }

    /**
     * Un crédito que hoy figura Liquidado pero recibió pagos DESPUÉS del
     * crédito nuevo seguía abierto ese día: el cliente es adicional.
     *
     * @test
     */
    public function el_pago_posterior_prueba_que_el_credito_viejo_seguia_abierto(): void
    {
        $cliente = $this->makeClient();
        $viejo = $this->makeCredit($cliente, '2026-06-01', 'Liquidado');
        $this->makePayment($viejo, '2026-08-10'); // después del crédito nuevo
        $this->makeCredit($cliente, self::DIA);

        $counts = $this->counts();

        $this->assertSame(0, $counts['settled_clients']);
        $this->assertSame(1, $counts['additional_clients']);
    }

    /**
     * Se cuentan CLIENTES, no créditos.
     *
     * @test
     */
    public function un_cliente_con_dos_creditos_cuenta_una_vez_por_categoria(): void
    {
        $cliente = $this->makeClient();
        $this->makeCredit($cliente, self::DIA);
        $this->makeCredit($cliente, '2026-08-08');

        $counts = $this->counts();

        $this->assertSame(1, $counts['new_clients']);
        $this->assertSame(1, $counts['additional_clients'],
            'El segundo crédito lo encuentra con el primero abierto.');
        $this->assertSame(2, $counts['credits_granted']);
    }

    /**
     * EL TEST QUE IMPORTA PARA EL MODAL: la lista tiene que dar exactamente el
     * número que muestra la pantalla, en las tres categorías. Si alguna vez se
     * separan, el reporte deja de ser auditable.
     *
     * @test
     */
    public function el_detalle_del_modal_da_exactamente_el_numero_de_la_pantalla(): void
    {
        $this->escenarioCompleto();

        $counts = $this->counts();

        $this->assertCount($counts['new_clients'], $this->detalle('new'));
        $this->assertCount($counts['settled_clients'], $this->detalle('settled'));
        $this->assertCount($counts['additional_clients'], $this->detalle('additional'));

        $nombres = array_column($this->detalle('settled'), 'client_name');
        sort($nombres);
        $this->assertSame(['C LIQUIDO DENTRO', 'D LIQUIDO ANTES'], $nombres,
            'La lista nombra a los clientes concretos, no un número suelto.');
    }

    /**
     * Un cliente puede caer en DOS categorías si tomó dos créditos distintos:
     * el conteo lo cuenta en las dos, así que el modal también tiene que
     * mostrarlo en las dos. Al deduplicar por cliente sin mirar la categoría,
     * la lista perdía filas y no cuadraba con el número.
     *
     * @test
     */
    public function un_cliente_en_dos_categorias_aparece_en_las_dos_listas(): void
    {
        $cliente = $this->makeClient('DOBLE CATEGORIA');

        // Primer crédito del período: es su primer crédito -> nuevo.
        $primero = $this->makeCredit($cliente, self::DIA);
        // Lo liquida y toma otro: sin nada abierto -> liquidó y tomó otro.
        $primero->update(['status' => 'Liquidado']);
        $this->makePayment($primero, '2026-08-06');
        $this->makeCredit($cliente, '2026-08-08');

        $counts = $this->counts();
        $this->assertSame(1, $counts['new_clients']);
        $this->assertSame(1, $counts['settled_clients']);

        $this->assertCount(1, $this->detalle('new'));
        $this->assertCount(1, $this->detalle('settled'));
    }

    /** @test */
    public function el_detalle_se_puede_acotar_a_un_dia(): void
    {
        $this->escenarioCompleto();

        $delDia = app(LiquidationService::class)->getCreditClassificationDetail(
            self::DESDE, self::HASTA, 'new', null, null, null, $this->seller->id, self::DIA
        );
        $otroDia = app(LiquidationService::class)->getCreditClassificationDetail(
            self::DESDE, self::HASTA, 'new', null, null, null, $this->seller->id, '2026-08-09'
        );

        $this->assertCount(1, $delDia);
        $this->assertCount(0, $otroDia, 'Un día sin colocación no devuelve clientes.');
    }

    /** @test */
    public function la_categoria_invalida_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(LiquidationService::class)->getCreditClassificationDetail(
            self::DESDE, self::HASTA, 'renovaciones', null, null, null, $this->seller->id
        );
    }

    /** @test */
    public function agrupa_por_dia_y_por_ciudad(): void
    {
        $this->escenarioCompleto();

        $svc = app(LiquidationService::class);

        $porDia = $svc->getCreditCountsByGroup(self::DESDE, self::HASTA, 'day', null, null, null, $this->seller->id);
        $this->assertArrayHasKey(self::DIA, $porDia, 'El día se indexa por el día de negocio del crédito.');
        $this->assertSame(4, $porDia[self::DIA]['credits_granted']);

        $porCiudad = $svc->getCreditCountsByGroup(self::DESDE, self::HASTA, 'city', null, null, $this->city->id);
        $this->assertSame(1, $porCiudad[$this->city->id]['new_clients']);
        $this->assertSame(2, $porCiudad[$this->city->id]['settled_clients']);
    }

    /**
     * El reporte por ciudad tiene que traer la moneda del país de la ruta: la
     * tabla mezcla Perú, Colombia, Bolivia y Argentina, y antes todas las
     * cifras se mostraban con un "$" fijo.
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
        $this->assertSame(2, $fila->settled_clients);
        $this->assertSame(1, $fila->additional_clients);
    }
}
