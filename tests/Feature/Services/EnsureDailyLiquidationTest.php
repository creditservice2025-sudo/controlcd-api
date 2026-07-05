<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blinda la red de seguridad LiquidationService::ensureDailyLiquidation, que
 * garantiza la liquidación del día al cobrar (por si la auto-apertura del login
 * falló) sin romper el flujo que la invoca. Evita días de puro cobro huérfanos.
 */
class EnsureDailyLiquidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSeller(): Seller
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        return Seller::factory()->create(['city_id' => $city->id]);
    }

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    public function test_crea_la_liquidacion_del_dia_si_falta(): void
    {
        $seller = $this->makeSeller();
        $this->assertSame(0, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());

        $liq = $this->svc()->ensureDailyLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $this->assertNotNull($liq);
        $this->assertSame('2026-07-01', $liq->date->toDateString());
        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_es_idempotente_no_duplica(): void
    {
        $seller = $this->makeSeller();

        $this->svc()->ensureDailyLiquidation($seller->id, '2026-07-01', 'America/Lima');
        $this->svc()->ensureDailyLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_resuelve_la_zona_del_vendedor_si_no_se_pasa(): void
    {
        $seller = $this->makeSeller();

        $liq = $this->svc()->ensureDailyLiquidation($seller->id, '2026-07-01');

        $this->assertNotNull($liq);
        $this->assertSame('2026-07-01', $liq->date->toDateString());
    }

    public function test_no_lanza_y_devuelve_null_ante_un_error(): void
    {
        // Seller inexistente: getOrCreate falla internamente; ensure NO debe lanzar.
        $liq = $this->svc()->ensureDailyLiquidation(999999, '2026-07-01', 'America/Lima');

        $this->assertNull($liq);
    }
}
