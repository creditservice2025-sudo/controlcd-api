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
 * Freeze de lo firmado: recalculateLiquidation NO debe tocar una liquidación
 * 'approved' (queda como ancla inmutable de la cadena), pero SÍ debe seguir
 * recalculando las 'En curso' / 'pending'. Reabrir es la única puerta para
 * cambiar una aprobada.
 */
class LiquidationFreezeApprovedTest extends TestCase
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

    public function test_una_aprobada_no_se_recalcula(): void
    {
        $seller = $this->makeSeller();
        $liq = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        // La firmamos y le ponemos un valor "raro" que NO coincide con lo que
        // daría la fórmula (sin movimientos, la fórmula daría 0).
        $liq->update(['status' => 'approved', 'real_to_deliver' => 99999.99, 'initial_cash' => 12345.67]);

        $this->svc()->recalculateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $fresh = Liquidation::find($liq->id);
        $this->assertSame('99999.99', (string) $fresh->real_to_deliver, 'La aprobada NO debe cambiar su real_to_deliver');
        $this->assertSame('12345.67', (string) $fresh->initial_cash, 'La aprobada NO debe cambiar su initial_cash');
    }

    public function test_una_en_curso_si_se_recalcula(): void
    {
        $seller = $this->makeSeller();
        $liq = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        // Está 'En curso'. Le metemos un rtd falso; el recálculo debe corregirlo.
        $liq->update(['real_to_deliver' => 88888.88]);

        $this->svc()->recalculateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $fresh = Liquidation::find($liq->id);
        $this->assertNotSame('88888.88', (string) $fresh->real_to_deliver, 'La En curso SÍ debe recalcularse');
    }

    public function test_cascada_saltea_aprobadas_pero_reencadena_las_vivas(): void
    {
        $seller = $this->makeSeller();
        // Día 1 aprobado con rtd ancla; día 2 en curso.
        $d1 = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');
        $d1->update(['status' => 'approved', 'real_to_deliver' => 500.00]);
        $d2 = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-02', 'America/Lima');
        $d2->update(['initial_cash' => 0, 'real_to_deliver' => 0]);

        // Recalcular desde el día 1 en cascada.
        $this->svc()->recalculateNextLiquidations($seller->id, '2026-07-01');

        $d1f = Liquidation::find($d1->id);
        $d2f = Liquidation::find($d2->id);

        $this->assertSame('500.00', (string) $d1f->real_to_deliver, 'El día aprobado queda intacto');
        // El día 2 (vivo) toma como caja anterior el rtd congelado del día 1.
        $this->assertEquals(500.00, (float) $d2f->initial_cash, 'La cadena usa el rtd congelado de la aprobada como caja anterior');
    }
}
