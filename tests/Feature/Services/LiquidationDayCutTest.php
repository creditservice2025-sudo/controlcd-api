<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El corte del día de los créditos irrecuperables usa la zona del VENDEDOR
 * (límites UTC del día local), no whereDate(updated_at) en UTC. Un crédito que
 * pasa a irrecuperable la noche del día D en la zona del vendedor —que en UTC ya
 * es D+1— debe contar para D, no para D+1.
 */
class LiquidationDayCutTest extends TestCase
{
    use RefreshDatabase;

    public function test_irrecoverable_se_corta_en_la_zona_del_vendedor(): void
    {
        // Perú (America/Lima, UTC-5).
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        // Crédito que pasó a irrecuperable el 2026-07-02 02:00 UTC = 2026-07-01
        // 21:00 en Lima. Por fecha UTC caería en el 02; por zona del vendedor, 01.
        $irr = Credit::factory()->create([
            'seller_id' => $seller->id, 'client_id' => $client->id,
            'payment_frequency' => 'Diaria', 'status' => 'Cartera Irrecuperable',
        ]);
        DB::table('credits')->where('id', $irr->id)->update(['updated_at' => '2026-07-02 02:00:00']);
        DB::table('installments')->insert([
            'credit_id' => $irr->id, 'quota_number' => 1, 'due_date' => '2026-07-01',
            'quota_amount' => 200, 'paid_amount' => 0, 'status' => 'Pendiente',
            'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-02 02:00:00',
        ]);

        $svc = app(LiquidationService::class);

        // Debe contar para el 01 (día real en Lima).
        $svc->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');
        $svc->recalculateLiquidation($seller->id, '2026-07-01');
        $d1 = \App\Models\Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->first();
        $this->assertEqualsWithDelta(200, (float) $d1->irrecoverable_credits_amount, 0.01, 'Debe contar en el 01 (Lima)');

        // NO debe contar para el 02.
        $svc->getOrCreateLiquidation($seller->id, '2026-07-02', 'America/Lima');
        $svc->recalculateLiquidation($seller->id, '2026-07-02');
        $d2 = \App\Models\Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-02')->first();
        $this->assertEqualsWithDelta(0, (float) $d2->irrecoverable_credits_amount, 0.01, 'No debe contar en el 02');
    }
}
