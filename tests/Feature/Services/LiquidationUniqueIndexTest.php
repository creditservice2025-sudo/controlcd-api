<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Services\LiquidationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Blinda el índice único que impide DOS liquidaciones VIVAS para el mismo
 * (seller_id, día) —cerrando la carrera que producía duplicados (07-02)— y
 * verifica que el flujo de REAPERTURA (soft-delete + recrear misma fecha) sigue
 * permitido, y que getOrCreateLiquidation es idempotente/atómico.
 */
class LiquidationUniqueIndexTest extends TestCase
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

    public function test_get_or_create_es_idempotente(): void
    {
        $seller = $this->makeSeller();

        $a = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');
        $b = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $this->assertEquals($a->id, $b->id);
        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_el_indice_bloquea_una_segunda_liquidacion_viva(): void
    {
        $seller = $this->makeSeller();
        $first = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        // Insert crudo de una fila viva idéntica (mismo seller+fecha, deleted_at NULL):
        // debe chocar contra liquidations_seller_active_date_unique (error 1062).
        $row = (array) DB::table('liquidations')->find($first->id);
        unset($row['id'], $row['active_date']); // id auto, active_date es columna generada

        $this->expectException(QueryException::class);
        DB::table('liquidations')->insert($row);
    }

    public function test_la_reapertura_soft_delete_y_recrear_sigue_permitida(): void
    {
        $seller = $this->makeSeller();

        $first = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');
        $first->delete(); // reapertura: soft-delete

        $second = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $this->assertNotEquals($first->id, $second->id, 'Debe crear una nueva liquidación tras la reapertura');
        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count(), 'Solo 1 viva');
        $this->assertSame(2, Liquidation::withTrashed()->where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count(), 'La borrada persiste');
    }

    public function test_get_or_create_devuelve_la_existente_ante_una_viva_previa(): void
    {
        $seller = $this->makeSeller();
        $first = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        // Simula "otra request ya la creó": getOrCreate debe devolverla, no duplicar.
        $again = $this->svc()->getOrCreateLiquidation($seller->id, '2026-07-01', 'America/Lima');

        $this->assertEquals($first->id, $again->id);
        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }
}
