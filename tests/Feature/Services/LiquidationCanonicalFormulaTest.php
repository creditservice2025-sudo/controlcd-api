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
 * Fija la ÚNICA fórmula de caja (calculateLiquidationMetrics), que ahora usan
 * TODOS los caminos —incluido el cierre (storeLiquidation), que recalcula desde
 * BD tras guardar—. Antes había 3 fórmulas divergentes.
 *
 * Reglas verificadas:
 *  - recaudo/gastos/créditos se recomputan desde las operaciones reales (BD),
 *    ignorando lo que hubiera guardado el APK;
 *  - real_to_deliver = initial + base_delivered + (income + collected + poliza)
 *                      − (expenses + new_credits + renewal);
 *  - irrecoverable NO se resta del real_to_deliver;
 *  - faltante/sobrante se calculan contra cash_delivered;
 *  - base_delivered y cash_delivered (manuales) se PRESERVAN.
 */
class LiquidationCanonicalFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculo_aplica_la_formula_canonica_y_preserva_manuales(): void
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        // Crédito vigente con un pago de 300 el 2026-07-01 (el recaudo real).
        $credit = Credit::factory()->create([
            'seller_id' => $seller->id, 'client_id' => $client->id,
            'payment_frequency' => 'Diaria', 'status' => 'Vigente',
        ]);
        DB::table('payments')->insert([
            'credit_id' => $credit->id, 'amount' => 300, 'unapplied_amount' => 0,
            'status' => 'Pagado', 'payment_method' => 'Efectivo',
            'payment_date' => '2026-07-01', 'business_date' => '2026-07-01',
            'created_at' => '2026-07-01 12:00:00', 'updated_at' => '2026-07-01 12:00:00',
        ]);

        // Crédito irrecuperable con cuota pendiente de 200 (irrecoverable = 200).
        $irr = Credit::factory()->create([
            'seller_id' => $seller->id, 'client_id' => $client->id,
            'payment_frequency' => 'Diaria', 'status' => 'Cartera Irrecuperable',
        ]);
        DB::table('credits')->where('id', $irr->id)->update(['updated_at' => '2026-07-01 12:00:00']);
        DB::table('installments')->insert([
            'credit_id' => $irr->id, 'quota_number' => 1, 'due_date' => '2026-07-01',
            'quota_amount' => 200, 'paid_amount' => 0, 'status' => 'Pendiente',
            'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 12:00:00',
        ]);

        $svc = app(LiquidationService::class);

        // Crea la liquidación del día y fija montos manuales + valores "del APK"
        // deliberadamente MAL, para probar que el recálculo los corrige.
        $liq = $svc->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');
        $liq->update([
            'base_delivered' => 500,
            'cash_delivered' => 1200,
            'total_collected' => 999,   // valor stale del APK
            'real_to_deliver' => 999,   // valor stale del APK
        ]);

        $svc->recalculateLiquidation($seller->id, '2026-07-01');
        $liq->refresh();

        // Recaudo recomputado desde BD (no el 999 del APK).
        $this->assertEqualsWithDelta(300, (float) $liq->total_collected, 0.01);
        // Irrecoverable computado…
        $this->assertEqualsWithDelta(200, (float) $liq->irrecoverable_credits_amount, 0.01);
        // …pero NO restado del real_to_deliver: 0 + 500 + 300 = 800.
        $this->assertEqualsWithDelta(800, (float) $liq->real_to_deliver, 0.01);
        // Manuales preservados.
        $this->assertEqualsWithDelta(500, (float) $liq->base_delivered, 0.01);
        $this->assertEqualsWithDelta(1200, (float) $liq->cash_delivered, 0.01);
        // Faltante/sobrante vs cash_delivered (1200 > 800 → sobrante 400).
        $this->assertEqualsWithDelta(400, (float) $liq->surplus, 0.01);
        $this->assertEqualsWithDelta(0, (float) $liq->shortage, 0.01);
    }
}
