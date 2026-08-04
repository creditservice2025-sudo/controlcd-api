<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Barrido de fin de día de liquidation:auto-daily: ninguna caja sobrevive a su
 * propia fecha.
 *
 * Antes, un día quedaba 'En curso' para siempre en tres escenarios que el cierre
 * de la ventana 23:55-23:59 no cubría:
 *   - la ruta no tiene auto_closures_collectors (nadie la cerraba nunca);
 *   - el día era no laborable y la fila ya existía (el comando hacía continue);
 *   - el vendedor fue dado de baja (la relación devolvía null).
 *
 * Corre contra controlcd_testing — NO toca producción.
 */
class AutoDailySweepTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Lima';

    private function seller(bool $autoClosures, bool $worksSundays): Seller
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);

        DB::table('seller_configs')->insert([
            'seller_id' => $seller->id,
            'auto_closures_collectors' => $autoClosures,
            'works_sundays' => $worksSundays,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $seller;
    }

    private function openDay(Seller $seller, string $date): Liquidation
    {
        return Liquidation::create([
            'seller_id' => $seller->id,
            'date' => $date,
            'currency' => 'PEN',
            'status' => 'En curso',
            'collection_target' => 0,
            'initial_cash' => 0,
            'base_delivered' => 0,
            'total_collected' => 0,
            'total_expenses' => 0,
            'total_income' => 0,
            'new_credits' => 0,
            'real_to_deliver' => 0,
            'shortage' => 0,
            'surplus' => 0,
            'cash_delivered' => 0,
        ]);
    }

    /** El domingo pasado más reciente (siempre una fecha ya vencida). */
    private function lastSunday(): string
    {
        return Carbon::now(self::TZ)->startOfWeek(Carbon::MONDAY)->subDay()->toDateString();
    }

    private function yesterday(): string
    {
        return Carbon::now(self::TZ)->subDay()->toDateString();
    }

    public function test_cierra_un_dia_vencido_de_una_ruta_sin_auto_cierre(): void
    {
        // 91 rutas en producción están así: el comando ni las cargaba.
        $seller = $this->seller(autoClosures: false, worksSundays: true);
        $liq = $this->openDay($seller, $this->yesterday());

        Artisan::call('liquidation:auto-daily');

        $this->assertSame('auto', $liq->fresh()->status);
    }

    public function test_cierra_el_domingo_vencido_de_una_ruta_que_no_trabaja_domingos(): void
    {
        // El caso reportado: la fila la creó otro camino y el cron la salteaba.
        $seller = $this->seller(autoClosures: true, worksSundays: false);
        $liq = $this->openDay($seller, $this->lastSunday());

        Artisan::call('liquidation:auto-daily');

        $this->assertSame('auto', $liq->fresh()->status);
    }

    public function test_cierra_el_dia_vencido_de_un_vendedor_dado_de_baja(): void
    {
        $seller = $this->seller(autoClosures: true, worksSundays: true);
        $liq = $this->openDay($seller, $this->yesterday());
        $seller->delete(); // soft-delete: la relación normal devolvería null

        Artisan::call('liquidation:auto-daily');

        $this->assertSame('auto', $liq->fresh()->status);
    }

    public function test_no_toca_la_caja_del_dia_en_curso(): void
    {
        // Una caja abierta HOY está abierta a propósito: el cobrador opera, o el
        // admin acaba de reabrirla.
        $seller = $this->seller(autoClosures: true, worksSundays: true);
        $liq = $this->openDay($seller, Carbon::now(self::TZ)->toDateString());

        Artisan::call('liquidation:auto-daily');

        $this->assertSame('En curso', $liq->fresh()->status);
    }

    public function test_no_sweep_desactiva_el_barrido(): void
    {
        $seller = $this->seller(autoClosures: false, worksSundays: true);
        $liq = $this->openDay($seller, $this->yesterday());

        Artisan::call('liquidation:auto-daily', ['--no-sweep' => true]);

        $this->assertSame('En curso', $liq->fresh()->status);
    }

    public function test_el_limite_trunca_pero_deja_el_resto_para_la_proxima_corrida(): void
    {
        $seller = $this->seller(autoClosures: false, worksSundays: true);
        $viejo = $this->openDay($seller, Carbon::now(self::TZ)->subDays(3)->toDateString());
        $nuevo = $this->openDay($seller, $this->yesterday());

        // Ordena por fecha ascendente: con límite 1 cierra el más viejo.
        Artisan::call('liquidation:auto-daily', ['--sweep-limit' => 1]);

        $this->assertSame('auto', $viejo->fresh()->status);
        $this->assertSame('En curso', $nuevo->fresh()->status);

        // La corrida siguiente termina el trabajo: el barrido es idempotente.
        Artisan::call('liquidation:auto-daily');

        $this->assertSame('auto', $nuevo->fresh()->status);
    }
}
