<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El backfill de moneda debe corregir SOLO la etiqueta `currency`, sin tocar
 * NINGÚN monto (rtd, initial_cash, etc.) ni el status. Y no debe tocar las que
 * ya están bien.
 */
class BackfillLiquidationCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function liq(int $sellerId, string $date, string $currency): Liquidation
    {
        return Liquidation::create([
            'seller_id' => $sellerId, 'date' => $date, 'status' => 'approved',
            'currency' => $currency, 'collection_target' => 0,
            'initial_cash' => 1000.50, 'total_collected' => 2000, 'total_income' => 0, 'poliza' => 0,
            'base_delivered' => 0, 'total_expenses' => 0, 'new_credits' => 0, 'renewal_disbursed_total' => 0,
            'irrecoverable_credits_amount' => 0, 'real_to_deliver' => 12345.67,
            'shortage' => 0, 'surplus' => 0, 'cash_delivered' => 0,
        ]);
    }

    public function test_corrige_solo_la_etiqueta_sin_tocar_montos(): void
    {
        $country = Country::factory()->create(['name' => 'Colombia', 'currency' => 'COP']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);

        $mal = $this->liq($seller->id, '2026-05-01', 'PEN');   // etiqueta incorrecta
        $bien = $this->liq($seller->id, '2026-05-02', 'COP');  // ya correcta

        $this->artisan('liquidations:backfill-currency --apply --force')->assertExitCode(0);

        $malFresh = Liquidation::find($mal->id);
        $bienFresh = Liquidation::find($bien->id);

        // 1) La etiqueta se corrigió.
        $this->assertSame('COP', $malFresh->currency);
        // 2) NINGÚN monto cambió (la garantía "sin alteraciones").
        $this->assertSame('12345.67', (string) $malFresh->real_to_deliver);
        $this->assertSame('1000.50', (string) $malFresh->initial_cash);
        $this->assertSame('2000.00', (string) $malFresh->total_collected);
        $this->assertSame('approved', $malFresh->status);
        // 3) La que ya estaba bien queda intacta.
        $this->assertSame('COP', $bienFresh->currency);
    }

    public function test_dry_run_no_cambia_nada(): void
    {
        $country = Country::factory()->create(['name' => 'Bolivia', 'currency' => 'BOB']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $mal = $this->liq($seller->id, '2026-05-01', 'PEN');

        $this->artisan('liquidations:backfill-currency')->assertExitCode(0); // sin --apply

        $this->assertSame('PEN', Liquidation::find($mal->id)->currency, 'Dry-run no debe cambiar la moneda');
    }
}
