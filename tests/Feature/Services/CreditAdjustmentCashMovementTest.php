<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Services\CreditService;
use App\Services\LiquidationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Blinda los movimientos de caja que genera el ajuste de un crédito.
 *
 * Al bajar o subir el capital de un crédito que NO se creó hoy, el sistema
 * mueve plata de verdad: si el capital baja, el cliente devuelve la diferencia
 * (ingreso); si sube, el cobrador la entrega (gasto). Lo mismo con la póliza.
 *
 * Bug de origen (crédito #144878, capital 400 -> 200): esos movimientos se
 * creaban SIN business_date, y como todos los totales de la liquidación filtran
 * esa columna por igualdad, el ingreso de $200 quedaba fuera de la caja. Se veía
 * en el listado "Ingresos del día" —que tiene rama de compatibilidad por
 * created_at— pero "Total Ingresos" marcaba $0.
 *
 * Lo que se fija acá:
 *  - el movimiento nace con business_date / timestamp / timezone;
 *  - la fecha sale de la zona del VENDEDOR, no del reloj del servidor;
 *  - la caja del día se recalcula sola y suma el movimiento;
 *  - un crédito creado HOY sigue sin generar movimiento (se ajusta por el
 *    rubro "Créditos Nuevos").
 */
class CreditAdjustmentCashMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Vendedor + el admin que hace el ajuste, ya autenticado.
     *
     * La sesión no es decorativa: CreditService resuelve el autor con
     * `Auth::id() ?? 1` y, sin nadie logueado, el 1 de respaldo apunta a un
     * usuario que en la BD de testing no existe y revienta la FK de
     * credits.last_modified_by. En producción el ajuste siempre lo hace
     * alguien con sesión.
     */
    private function makeSeller(string $countryName = 'Perú'): Seller
    {
        $this->actingAs(User::factory()->create());

        $country = Country::factory()->create(['name' => $countryName]);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /** Crédito de 400 con 3 cuotas pendientes, creado en $createdAt. */
    private function makeCredit(Seller $seller, string $createdAt): Credit
    {
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        $credit = Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'credit_value' => 400,
            'total_interest' => 20,
            'total_amount' => 480,
            'remaining_amount' => 480,
            'micro_insurance_percentage' => 3,
            'micro_insurance_amount' => 12,
            'number_installments' => 3,
            'payment_frequency' => 'Semanal',
            'first_quota_date' => '2026-09-10',
            'status' => 'Vigente',
        ]);

        DB::table('credits')->where('id', $credit->id)->update(['created_at' => $createdAt]);

        for ($n = 1; $n <= 3; $n++) {
            DB::table('installments')->insert([
                'credit_id' => $credit->id,
                'quota_number' => $n,
                'due_date' => date('Y-m-d', strtotime("2026-09-10 +" . ($n - 1) . " week")),
                'quota_amount' => 160,
                'paid_amount' => 0,
                'status' => 'Pendiente',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $credit->refresh();
    }

    /** Baja el capital del crédito de 400 a $nuevoCapital. */
    private function bajarCapital(Credit $credit, float $nuevoCapital = 200): array
    {
        $response = app(CreditService::class)->updateCreditFrequency(
            creditId: $credit->id,
            newFrequency: 'Semanal',
            newFirstQuotaDate: null,
            newInstallments: 3,
            newInterestRate: 20,
            newInsurancePercentage: 3,
            timezone: null,
            notes: 'Ajuste de prueba',
            newStartDate: null,
            newCreditValue: $nuevoCapital,
        );

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false, 'updateCreditFrequency falló: ' . ($data['message'] ?? json_encode($data)));

        return $data;
    }

    // ----------------------------------------------------------------------

    public function test_el_ingreso_del_ajuste_nace_con_la_fecha_de_negocio_del_vendedor(): void
    {
        Carbon::setTestNow('2026-09-12 14:05:02'); // 09:05 en Lima

        $seller = $this->makeSeller();
        $credit = $this->makeCredit($seller, '2026-09-03 12:20:56');

        $this->bajarCapital($credit);

        $income = DB::table('incomes')
            ->where('description', 'like', 'AJUSTE CAPITAL%')
            ->first();

        $this->assertNotNull($income, 'El ajuste a la baja debe generar el ingreso por la devolución de capital.');
        $this->assertEqualsWithDelta(200, (float) $income->value, 0.01);
        $this->assertSame('2026-09-12', substr((string) $income->business_date, 0, 10));
        $this->assertSame('2026-09-12 09:05:02', (string) $income->business_timestamp);
        $this->assertSame('America/Lima', $income->business_timezone);
    }

    public function test_el_gasto_de_la_poliza_tambien_queda_anclado(): void
    {
        Carbon::setTestNow('2026-09-12 14:05:02');

        $seller = $this->makeSeller();
        $credit = $this->makeCredit($seller, '2026-09-03 12:20:56');

        $this->bajarCapital($credit);

        // Póliza 3%: de 12 (sobre 400) a 6 (sobre 200) → se devuelven 6.
        $expense = DB::table('expenses')
            ->where('description', 'like', 'AJUSTE SEGURO%')
            ->first();

        $this->assertNotNull($expense);
        $this->assertEqualsWithDelta(6, (float) $expense->value, 0.01);
        $this->assertSame('2026-09-12', substr((string) $expense->business_date, 0, 10));
        $this->assertSame('America/Lima', $expense->business_timezone);
    }

    public function test_la_caja_del_dia_suma_el_ajuste_sin_intervencion(): void
    {
        Carbon::setTestNow('2026-09-12 14:05:02');

        $seller = $this->makeSeller();
        $credit = $this->makeCredit($seller, '2026-09-03 12:20:56');

        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');
        $antes = (float) $liq->real_to_deliver;

        $this->bajarCapital($credit);

        $liq->refresh();

        // Esto es exactamente lo que fallaba: el total marcaba 0.
        $this->assertEqualsWithDelta(200, (float) $liq->total_income, 0.01);
        $this->assertEqualsWithDelta(6, (float) $liq->total_expenses, 0.01);
        // real_to_deliver = … + income − expenses → +200 − 6 = +194.
        $this->assertEqualsWithDelta($antes + 194, (float) $liq->real_to_deliver, 0.01);
    }

    public function test_la_fecha_sale_de_la_zona_del_vendedor_y_no_del_reloj_utc(): void
    {
        // 02:30 UTC del 12 son las 21:30 del 11 en Lima: la jornada es el 11.
        Carbon::setTestNow('2026-09-12 02:30:00');

        $seller = $this->makeSeller();
        $credit = $this->makeCredit($seller, '2026-09-03 12:20:56');

        $this->bajarCapital($credit);

        $income = DB::table('incomes')->where('description', 'like', 'AJUSTE CAPITAL%')->first();

        $this->assertSame('2026-09-11', substr((string) $income->business_date, 0, 10));
        $this->assertSame('2026-09-11 21:30:00', (string) $income->business_timestamp);
    }

    public function test_el_vendedor_boliviano_ancla_en_su_propia_zona(): void
    {
        // Bolivia es UTC-4: 02:30 UTC del 12 son las 22:30 del 11 en La Paz.
        Carbon::setTestNow('2026-09-12 02:30:00');

        $seller = $this->makeSeller('Bolivia');
        $credit = $this->makeCredit($seller, '2026-09-03 12:20:56');

        $this->bajarCapital($credit);

        $income = DB::table('incomes')->where('description', 'like', 'AJUSTE CAPITAL%')->first();

        $this->assertSame('America/La_Paz', $income->business_timezone);
        $this->assertSame('2026-09-11 22:30:00', (string) $income->business_timestamp);
    }

    public function test_el_aumento_de_capital_genera_el_gasto_anclado(): void
    {
        Carbon::setTestNow('2026-09-12 14:05:02');

        $seller = $this->makeSeller();
        $credit = $this->makeCredit($seller, '2026-09-03 12:20:56');

        $this->bajarCapital($credit, 600); // sube: el cobrador entrega 200 más

        $expense = DB::table('expenses')->where('description', 'like', 'AJUSTE CAPITAL%')->first();

        $this->assertNotNull($expense, 'Subir el capital debe salir de la caja como gasto.');
        $this->assertEqualsWithDelta(200, (float) $expense->value, 0.01);
        $this->assertSame('2026-09-12', substr((string) $expense->business_date, 0, 10));
    }

    public function test_un_credito_creado_hoy_no_genera_movimiento_de_caja(): void
    {
        Carbon::setTestNow('2026-09-12 14:05:02');

        $seller = $this->makeSeller();
        // Creado hoy mismo en hora de Lima: el ajuste se refleja en el rubro
        // "Créditos Nuevos", no como ingreso/gasto. Comportamiento preexistente
        // que el arreglo NO debe alterar.
        $credit = $this->makeCredit($seller, '2026-09-12 13:00:00');

        $this->bajarCapital($credit);

        $this->assertSame(0, DB::table('incomes')->where('description', 'like', 'AJUSTE%')->count());
        $this->assertSame(0, DB::table('expenses')->where('description', 'like', 'AJUSTE%')->count());
    }
}
