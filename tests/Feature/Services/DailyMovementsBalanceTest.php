<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Income;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Extracto tipo banco: getDailyMovements devuelve por cada movimiento su
 * `efecto` (con signo) y el `saldo_despues` (saldo corriente), y concilia el
 * invariante: caja_anterior (initial_cash) + Σefectos == real_to_deliver.
 */
class DailyMovementsBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    public function test_saldo_corriente_concilia_con_el_rtd(): void
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $userId = $seller->user_id;
        $date = '2026-06-01';

        // Caja anterior 100; dos ingresos (30 y 20) => rtd 150.
        Liquidation::create([
            'seller_id' => $seller->id, 'date' => $date, 'status' => 'En curso',
            'currency' => 'PEN', 'collection_target' => 0,
            'initial_cash' => 100, 'total_collected' => 0, 'total_income' => 50, 'poliza' => 0,
            'base_delivered' => 0, 'total_expenses' => 0, 'new_credits' => 0, 'renewal_disbursed_total' => 0,
            'irrecoverable_credits_amount' => 0, 'real_to_deliver' => 150,
            'shortage' => 0, 'surplus' => 0, 'cash_delivered' => 0,
        ]);

        foreach ([30, 20] as $val) {
            Income::create([
                'value' => $val, 'description' => "ingreso $val", 'user_id' => $userId,
                'business_date' => $date, 'business_timestamp' => "$date 10:00:00",
                'business_timezone' => 'America/Lima',
            ]);
        }

        $r = $this->svc()->getDailyMovements($seller->id, $date, 'America/Lima');

        $this->assertSame(100.0, (float) $r['saldo_inicial']);
        $this->assertSame(150.0, (float) $r['saldo_final']);
        $this->assertSame(150.0, (float) $r['rtd_guardado']);
        $this->assertTrue($r['cuadra'], 'El saldo final debe conciliar con el rtd');
        $this->assertEqualsWithDelta(0.0, (float) $r['diferencia'], 0.001);

        // Cada movimiento trae efecto y saldo corriente.
        $data = collect($r['data']);
        $this->assertCount(2, $data);
        $this->assertNotNull($data->first()['efecto']);
        $this->assertNotNull($data->first()['saldo_despues']);
        // El mayor saldo_despues es el saldo final.
        $this->assertSame(150.0, (float) $data->max('saldo_despues'));
    }

    public function test_marca_descuadre_cuando_no_concilia(): void
    {
        $country = Country::factory()->create(['name' => 'Bolivia']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $date = '2026-06-02';

        // rtd guardado (200) que NO coincide con caja_anterior + movimientos (100+0).
        Liquidation::create([
            'seller_id' => $seller->id, 'date' => $date, 'status' => 'En curso',
            'currency' => 'PEN', 'collection_target' => 0,
            'initial_cash' => 100, 'total_collected' => 0, 'total_income' => 0, 'poliza' => 0,
            'base_delivered' => 0, 'total_expenses' => 0, 'new_credits' => 0, 'renewal_disbursed_total' => 0,
            'irrecoverable_credits_amount' => 0, 'real_to_deliver' => 200,
            'shortage' => 0, 'surplus' => 0, 'cash_delivered' => 0,
        ]);

        $r = $this->svc()->getDailyMovements($seller->id, $date, 'America/Lima');

        $this->assertFalse($r['cuadra'], 'Debe marcar descuadre');
        $this->assertEqualsWithDelta(-100.0, (float) $r['diferencia'], 0.001);
    }
}
