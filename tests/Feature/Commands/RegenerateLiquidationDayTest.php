<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Liquidation;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica el comando liquidations:regenerate-day, que genera una liquidación
 * diaria faltante con las operaciones del día (caso Nazaret 2026-07-01).
 * Corre contra controlcd_testing — NO toca producción.
 */
class RegenerateLiquidationDayTest extends TestCase
{
    use RefreshDatabase;

    /** Crea seller + un pago 'Pagado' con business_date = $date (recaudo del día). */
    private function seedSellerWithPaymentOnDay(string $date, float $amount = 90000): Seller
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $credit = Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'credit_value' => 300000,
            'total_interest' => 20,
            'total_amount' => 360000,
            'number_installments' => 4,
            'payment_frequency' => 'Semanal',
            'status' => 'Vigente',
        ]);

        $insId = DB::table('installments')->insertGetId([
            'credit_id' => $credit->id,
            'quota_number' => 1,
            'due_date' => $date,
            'quota_amount' => $amount,
            'paid_amount' => $amount,
            'status' => 'Pagado',
            'created_at' => $date . ' 00:00:00',
            'updated_at' => $date . ' 12:00:00',
        ]);

        $payId = DB::table('payments')->insertGetId([
            'credit_id' => $credit->id,
            'amount' => $amount,
            'unapplied_amount' => 0,
            'status' => 'Pagado',
            'payment_method' => 'Efectivo',
            'payment_date' => $date,
            'business_date' => $date,
            'created_at' => $date . ' 12:00:00',
            'updated_at' => $date . ' 12:00:00',
        ]);

        DB::table('payment_installments')->insert([
            'payment_id' => $payId,
            'installment_id' => $insId,
            'applied_amount' => $amount,
            'created_at' => $date . ' 12:00:00',
            'updated_at' => $date . ' 12:00:00',
        ]);

        return $seller;
    }

    public function test_genera_la_liquidacion_faltante_con_el_recaudo_del_dia(): void
    {
        $seller = $this->seedSellerWithPaymentOnDay('2026-07-01', 90000);

        // Precondición: no existe liquidación para el día.
        $this->assertSame(0, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());

        $this->artisan('liquidations:regenerate-day', [
            'seller' => $seller->id, 'date' => '2026-07-01', '--apply' => true,
        ])->assertExitCode(0);

        $liq = Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->first();
        $this->assertNotNull($liq, 'Debe crearse la liquidación del día faltante');
        $this->assertEqualsWithDelta(90000, (float) $liq->total_collected, 0.01);
    }

    public function test_es_idempotente(): void
    {
        $seller = $this->seedSellerWithPaymentOnDay('2026-07-01', 90000);

        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--apply' => true])->assertExitCode(0);
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--apply' => true])->assertExitCode(0);

        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_dry_run_no_crea_nada(): void
    {
        $seller = $this->seedSellerWithPaymentOnDay('2026-07-01', 90000);

        // Sin --apply: no debe crear la liquidación.
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01'])->assertExitCode(0);

        $this->assertSame(0, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_falla_con_vendedor_inexistente(): void
    {
        $this->artisan('liquidations:regenerate-day', ['seller' => 999999, 'date' => '2026-07-01'])
            ->assertExitCode(1);
    }

    public function test_revert_dry_run_no_borra(): void
    {
        $seller = $this->seedSellerWithPaymentOnDay('2026-07-01', 90000);
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--apply' => true])->assertExitCode(0);

        // --revert sin --apply: no debe borrar.
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--revert' => true])->assertExitCode(0);

        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_revert_aplica_borra_la_liquidacion(): void
    {
        $seller = $this->seedSellerWithPaymentOnDay('2026-07-01', 90000);
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());

        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--revert' => true, '--apply' => true])->assertExitCode(0);

        // Queda soft-deleted (0 vivas, 1 con trashed).
        $this->assertSame(0, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
        $this->assertSame(1, Liquidation::withTrashed()->where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }

    public function test_revert_rechaza_si_no_esta_en_curso(): void
    {
        $seller = $this->seedSellerWithPaymentOnDay('2026-07-01', 90000);
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--apply' => true])->assertExitCode(0);

        // Simula que el día fue cerrado/aprobado después de regenerar.
        Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->update(['status' => 'approved']);

        // El revert debe RECHAZAR (no tocar una liquidación ya cerrada).
        $this->artisan('liquidations:regenerate-day', ['seller' => $seller->id, 'date' => '2026-07-01', '--revert' => true, '--apply' => true])->assertExitCode(1);

        $this->assertSame(1, Liquidation::where('seller_id', $seller->id)->whereDate('date', '2026-07-01')->count());
    }
}
