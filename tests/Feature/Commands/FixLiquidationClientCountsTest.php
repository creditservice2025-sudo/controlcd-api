<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica `liquidations:fix-client-counts`, que repara la "A" y la "S" de
 * P/A/L/S/N ya grabadas en las liquidaciones.
 *
 * Lo que más importa acá NO es que corrija el número, sino lo que NO hace:
 * se autorizó tocar conteos, no plata. Por eso hay dos tests dedicados a que
 * los montos queden byte a byte iguales, uno por cada modo de recorrido.
 *
 * Corre contra la BD de testing (controlcd_testing) — NO toca producción.
 */
class FixLiquidationClientCountsTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'liquidations:fix-client-counts';

    private function makeSeller(): Seller
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * Un vendedor con 1 cliente de cartera real y 1 cliente cuyo único crédito
     * "Vigente" está BORRADO — el caso que inflaba el conteo.
     */
    private function escenario(): Seller
    {
        $seller = $this->makeSeller();

        $bueno = Client::factory()->create([
            'seller_id' => $seller->id, 'status' => 'active',
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        Credit::factory()->create([
            'seller_id' => $seller->id, 'client_id' => $bueno->id,
            'status' => 'Vigente', 'payment_frequency' => 'Semanal',
            'business_date' => '2026-09-01', 'business_timezone' => 'America/Lima',
        ]);

        $roto = Client::factory()->create([
            'seller_id' => $seller->id, 'status' => 'active',
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);
        $borrado = Credit::factory()->create([
            'seller_id' => $seller->id, 'client_id' => $roto->id,
            'status' => 'Vigente', 'payment_frequency' => 'Semanal',
            'business_date' => '2026-09-01', 'business_timezone' => 'America/Lima',
        ]);
        DB::table('credits')->where('id', $borrado->id)
            ->update(['deleted_at' => '2026-09-01 12:00:00']);

        return $seller;
    }

    /** Liquidación del día con los conteos viejos (mal) ya grabados. */
    private function liquidacionCon(Seller $seller, string $fecha, int $a, int $s, string $status = 'En curso')
    {
        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($seller->id, $fecha, 'America/Lima');

        DB::table('liquidations')->where('id', $liq->id)->update([
            'active_clients_with_credit_count' => $a,
            'clients_without_credit_count' => $s,
            'status' => $status,
        ]);

        return $liq->fresh();
    }

    // ----------------------------------------------------------------------

    public function test_dry_run_no_escribe_nada(): void
    {
        $seller = $this->escenario();
        $liq = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, ['--seller' => [$seller->id]])->assertSuccessful();

        $liq->refresh();
        $this->assertSame(2, (int) $liq->active_clients_with_credit_count);
        $this->assertSame(0, (int) $liq->clients_without_credit_count);
        $this->assertSame(0, DB::table('liquidation_audits')
            ->where('action', 'correccion_conteo_clientes')->count());
    }

    public function test_apply_corrige_el_conteo(): void
    {
        $seller = $this->escenario();
        $liq = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])
            ->assertSuccessful();

        $liq->refresh();
        $this->assertSame(1, (int) $liq->active_clients_with_credit_count, 'El crédito borrado deja de contar.');
        $this->assertSame(1, (int) $liq->clients_without_credit_count);
    }

    public function test_no_toca_ningun_monto(): void
    {
        $seller = $this->escenario();
        $liq = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        DB::table('liquidations')->where('id', $liq->id)->update([
            'initial_cash' => 1000, 'base_delivered' => 500, 'total_collected' => 750,
            'total_expenses' => 41, 'total_income' => 200, 'new_credits' => 600,
            'poliza' => 18, 'real_to_deliver' => -1435.92, 'shortage' => 1435.92,
            'surplus' => 0, 'cash_delivered' => 123.45,
        ]);

        $montos = ['initial_cash', 'base_delivered', 'total_collected', 'total_expenses',
                   'total_income', 'new_credits', 'poliza', 'real_to_deliver',
                   'shortage', 'surplus', 'cash_delivered'];

        $antes = (array) DB::table('liquidations')->where('id', $liq->id)->first();

        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])
            ->assertSuccessful();

        $despues = (array) DB::table('liquidations')->where('id', $liq->id)->first();

        foreach ($montos as $campo) {
            $this->assertSame(
                $antes[$campo],
                $despues[$campo],
                "El comando movió {$campo}, y solo tiene permitido tocar conteos."
            );
        }

        // El conteo sí cambió: si no, el test pasaría por no hacer nada.
        $this->assertNotSame(
            $antes['active_clients_with_credit_count'],
            $despues['active_clients_with_credit_count']
        );
    }

    public function test_deja_el_historico_del_cambio_en_liquidation_audits(): void
    {
        $seller = $this->escenario();
        $liq = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])
            ->assertSuccessful();

        $audit = DB::table('liquidation_audits')
            ->where('liquidation_id', $liq->id)
            ->where('action', 'correccion_conteo_clientes')
            ->first();

        $this->assertNotNull($audit, 'Cada corrección debe dejar su rastro.');

        $cambios = json_decode($audit->changes, true);
        $this->assertSame(2, $cambios['active_clients_with_credit_count']['antes']);
        $this->assertSame(1, $cambios['active_clients_with_credit_count']['despues']);
        $this->assertSame(0, $cambios['clients_without_credit_count']['antes']);
        $this->assertSame(1, $cambios['clients_without_credit_count']['despues']);
        $this->assertSame('2026-09-12', $cambios['fecha_liquidacion']);
    }

    public function test_reconstruye_el_corte_de_cada_dia_y_no_la_foto_de_hoy(): void
    {
        $seller = $this->escenario();

        // Día anterior al primer crédito: la cartera estaba vacía. El bug
        // escribía acá el número de hoy; el comando debe dejar 0.
        $vieja = $this->liquidacionCon($seller, '2026-08-20', 2, 0);
        $nueva = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(0, (int) $vieja->fresh()->active_clients_with_credit_count);
        $this->assertSame(1, (int) $nueva->fresh()->active_clients_with_credit_count);
    }

    public function test_solo_abiertas_saltea_las_liquidaciones_aprobadas(): void
    {
        $seller = $this->escenario();
        $aprobada = $this->liquidacionCon($seller, '2026-09-11', 2, 0, 'approved');
        $abierta = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, [
            '--seller' => [$seller->id], '--apply' => true, '--solo-abiertas' => true,
        ])->assertSuccessful();

        $this->assertSame(2, (int) $aprobada->fresh()->active_clients_with_credit_count, 'La aprobada no se toca.');
        $this->assertSame(1, (int) $abierta->fresh()->active_clients_with_credit_count);
    }

    public function test_por_defecto_si_corrige_las_aprobadas(): void
    {
        $seller = $this->escenario();
        $aprobada = $this->liquidacionCon($seller, '2026-09-12', 2, 0, 'approved');

        // Se permite porque no se toca plata: el cerrojo de caja firmada
        // protege los montos, y acá ninguno cambia.
        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, (int) $aprobada->fresh()->active_clients_with_credit_count);
    }

    public function test_es_idempotente(): void
    {
        $seller = $this->escenario();
        $liq = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])->assertSuccessful();
        $this->artisan(self::COMANDO, ['--seller' => [$seller->id], '--apply' => true])->assertSuccessful();

        $this->assertSame(1, (int) $liq->fresh()->active_clients_with_credit_count);
        // La segunda corrida no encuentra nada que corregir: una sola auditoría.
        $this->assertSame(1, DB::table('liquidation_audits')
            ->where('liquidation_id', $liq->id)
            ->where('action', 'correccion_conteo_clientes')->count());
    }

    /**
     * Los dos modos de recorrido tienen que dar EXACTAMENTE lo mismo.
     *
     * --por-vendedor no es una variante cosmética: es el único modo que termina
     * a escala real (el recorrido por fecha no produce salida en +35 min sobre
     * 27.080 liquidaciones), así que es el que se corre en producción. Si
     * divergieran, el modo probado a mano y el que se ejecuta de verdad
     * dejarían de ser el mismo y nadie se enteraría.
     */
    public function test_los_dos_modos_de_recorrido_dan_el_mismo_resultado(): void
    {
        $unoA = $this->escenario();
        $unoB = $this->escenario();

        foreach ([$unoA, $unoB] as $seller) {
            foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $f) {
                $this->liquidacionCon($seller, $f, 2, 0);
            }
        }

        $leer = fn () => DB::table('liquidations')->orderBy('id')
            ->get(['id', 'active_clients_with_credit_count', 'clients_without_credit_count'])
            ->map(fn ($l) => "{$l->id}:{$l->active_clients_with_credit_count}/{$l->clients_without_credit_count}")
            ->implode(',');

        $this->artisan(self::COMANDO, ['--apply' => true])->assertSuccessful();
        $porFecha = $leer();

        DB::table('liquidations')->update([
            'active_clients_with_credit_count' => 2,
            'clients_without_credit_count' => 0,
        ]);

        $this->artisan(self::COMANDO, ['--apply' => true, '--por-vendedor' => true])->assertSuccessful();
        $porVendedor = $leer();

        $this->assertSame($porFecha, $porVendedor, 'Los dos recorridos deben producir los mismos conteos.');
        // Y que efectivamente corrigieron algo, para que la igualdad no sea
        // dos veces "no hice nada".
        $this->assertStringNotContainsString(':2/0', $porVendedor);
    }

    public function test_por_vendedor_tampoco_toca_montos(): void
    {
        $seller = $this->escenario();
        $liq = $this->liquidacionCon($seller, '2026-09-12', 2, 0);

        DB::table('liquidations')->where('id', $liq->id)->update([
            'initial_cash' => 1000, 'total_collected' => 750, 'real_to_deliver' => -1435.92,
            'shortage' => 1435.92, 'cash_delivered' => 123.45,
        ]);

        $montos = ['initial_cash', 'total_collected', 'real_to_deliver', 'shortage', 'cash_delivered'];
        $antes = (array) DB::table('liquidations')->where('id', $liq->id)->first();

        $this->artisan(self::COMANDO, ['--apply' => true, '--por-vendedor' => true])
            ->assertSuccessful();

        $despues = (array) DB::table('liquidations')->where('id', $liq->id)->first();

        foreach ($montos as $campo) {
            $this->assertSame($antes[$campo], $despues[$campo], "El modo por-vendedor movió {$campo}.");
        }
        $this->assertSame(1, (int) $despues['active_clients_with_credit_count']);
    }

    public function test_el_filtro_por_vendedor_acota_el_alcance(): void
    {
        $unoA = $this->escenario();
        $unoB = $this->escenario();

        $liqA = $this->liquidacionCon($unoA, '2026-09-12', 2, 0);
        $liqB = $this->liquidacionCon($unoB, '2026-09-12', 2, 0);

        $this->artisan(self::COMANDO, ['--seller' => [$unoA->id], '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, (int) $liqA->fresh()->active_clients_with_credit_count);
        $this->assertSame(2, (int) $liqB->fresh()->active_clients_with_credit_count, 'El otro vendedor queda intacto.');
    }
}
