<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica `credits:backfill-adjustment-business-date`, que repara los
 * ingresos/gastos generados por ajustes de crédito que quedaron con
 * business_date en null y por eso nunca entraron en los totales de la caja
 * (caso testigo: crédito #144878, $200 de ingreso invisibles).
 *
 * Corre contra la BD de testing (controlcd_testing) — NO toca producción.
 */
class BackfillCreditAdjustmentBusinessDateTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'credits:backfill-adjustment-business-date';

    /** Vendedor peruano (America/Lima, UTC-5) con su usuario. */
    private function makeSeller(string $countryName = 'Perú'): Seller
    {
        $country = Country::factory()->create(['name' => $countryName]);
        $city = City::factory()->create(['country_id' => $country->id]);

        return Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * Inserta un movimiento tal cual lo dejaba el bug: con la descripción que
     * produce CreditService y las tres columnas business_* en null.
     */
    private function movimientoHuerfano(
        string $tabla,
        int $userId,
        int $creditId,
        float $valor,
        string $createdAtUtc,
        string $concepto = 'CAPITAL'
    ): int {
        $desc = $concepto === 'CAPITAL'
            ? "AJUSTE CAPITAL CRÉDITO #{$creditId} (Creado: 2026-09-03). Cambio: \$400.00 -> \$200.00. ENTRADA A CAJA."
            : "AJUSTE SEGURO CRÉDITO #{$creditId}. Diferencia devuelta: \${$valor}. SALIDA DE CAJA.";

        $fila = [
            'value' => $valor,
            'description' => $desc,
            'user_id' => $userId,
            'business_date' => null,
            'business_timestamp' => null,
            'business_timezone' => null,
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ];

        if ($tabla === 'expenses') {
            $fila['status'] = 'Aprobado';
        }

        return DB::table($tabla)->insertGetId($fila);
    }

    // ----------------------------------------------------------------------

    public function test_dry_run_no_escribe_nada(): void
    {
        $seller = $this->makeSeller();
        $id = $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 09:05:02');

        $this->artisan(self::COMANDO, ['--credit' => [144878]])
            ->assertSuccessful();

        $this->assertNull(
            DB::table('incomes')->where('id', $id)->value('business_date'),
            'El dry-run no debe escribir la fecha de negocio.'
        );
    }

    public function test_apply_ancla_el_movimiento_en_la_zona_del_vendedor(): void
    {
        $seller = $this->makeSeller(); // America/Lima = UTC-5

        // 02:30 UTC del 12 son las 21:30 del 11 en Lima: la jornada correcta es
        // el 11, no el 12. Es el caso que hace que leer created_at "tal cual"
        // mande el movimiento a la caja equivocada.
        $id = $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 02:30:00');

        $this->artisan(self::COMANDO, ['--credit' => [144878], '--apply' => true])
            ->assertSuccessful();

        $fila = DB::table('incomes')->where('id', $id)->first();

        $this->assertSame('2026-09-11', substr((string) $fila->business_date, 0, 10));
        $this->assertSame('2026-09-11 21:30:00', (string) $fila->business_timestamp);
        $this->assertSame('America/Lima', $fila->business_timezone);
    }

    public function test_el_ingreso_reparado_entra_en_el_total_de_la_caja(): void
    {
        $seller = $this->makeSeller();

        $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 14:05:02');
        $this->movimientoHuerfano('expenses', $seller->user_id, 144878, 6, '2026-09-12 14:05:02', 'SEGURO');

        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');

        // Antes del backfill la caja no ve ninguno de los dos movimientos.
        app(LiquidationService::class)->recalculateLiquidation($seller->id, '2026-09-12');
        $liq->refresh();
        $this->assertEqualsWithDelta(0, (float) $liq->total_income, 0.01);
        $this->assertEqualsWithDelta(0, (float) $liq->total_expenses, 0.01);
        $antes = (float) $liq->real_to_deliver;

        $this->artisan(self::COMANDO, ['--credit' => [144878], '--apply' => true])
            ->assertSuccessful();

        $liq->refresh();
        $this->assertEqualsWithDelta(200, (float) $liq->total_income, 0.01);
        $this->assertEqualsWithDelta(6, (float) $liq->total_expenses, 0.01);
        // real_to_deliver = … + income − expenses → +200 − 6 = +194.
        $this->assertEqualsWithDelta($antes + 194, (float) $liq->real_to_deliver, 0.01);
    }

    public function test_no_toca_movimientos_que_ya_tienen_business_date(): void
    {
        $seller = $this->makeSeller();

        $id = DB::table('incomes')->insertGetId([
            'value' => 200,
            'description' => 'AJUSTE CAPITAL CRÉDITO #144878 (Creado: 2026-09-03). Cambio: $400.00 -> $200.00. ENTRADA A CAJA.',
            'user_id' => $seller->user_id,
            'business_date' => '2026-01-01',      // fecha ya anclada, aunque sea otra
            'business_timezone' => 'America/Bogota',
            'created_at' => '2026-09-12 09:05:02',
            'updated_at' => '2026-09-12 09:05:02',
        ]);

        $this->artisan(self::COMANDO, ['--apply' => true])->assertSuccessful();

        $fila = DB::table('incomes')->where('id', $id)->first();
        $this->assertSame('2026-01-01', substr((string) $fila->business_date, 0, 10));
        $this->assertSame('America/Bogota', $fila->business_timezone);
    }

    public function test_no_toca_movimientos_que_no_son_ajustes_de_credito(): void
    {
        $seller = $this->makeSeller();

        $id = DB::table('incomes')->insertGetId([
            'value' => 50,
            'description' => 'Venta de chatarra',   // ingreso común sin anclar
            'user_id' => $seller->user_id,
            'business_date' => null,
            'created_at' => '2026-09-12 09:05:02',
            'updated_at' => '2026-09-12 09:05:02',
        ]);

        $this->artisan(self::COMANDO, ['--apply' => true])->assertSuccessful();

        $this->assertNull(
            DB::table('incomes')->where('id', $id)->value('business_date'),
            'El backfill solo debe tocar los movimientos que generó un ajuste de crédito.'
        );
    }

    public function test_el_filtro_por_credito_no_pega_con_ids_que_lo_contienen(): void
    {
        $seller = $this->makeSeller();

        $buscado = $this->movimientoHuerfano('incomes', $seller->user_id, 1448, 10, '2026-09-12 14:00:00');
        $vecino = $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 14:00:00');

        // El LIKE '%#1448%' alcanza a los dos; el regex del comando descarta
        // el #144878 por id exacto.
        $this->artisan(self::COMANDO, ['--credit' => [1448], '--apply' => true])
            ->assertSuccessful();

        $this->assertNotNull(DB::table('incomes')->where('id', $buscado)->value('business_date'));
        $this->assertNull(
            DB::table('incomes')->where('id', $vecino)->value('business_date'),
            'El crédito #144878 no debe entrar en un filtro por #1448.'
        );
    }

    public function test_no_recalcula_la_caja_de_un_dia_aprobado(): void
    {
        $seller = $this->makeSeller();
        $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 14:05:02');

        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');
        $liq->update(['status' => 'approved', 'real_to_deliver' => 1000, 'total_income' => 0]);

        $this->artisan(self::COMANDO, ['--credit' => [144878], '--apply' => true])
            ->assertSuccessful();

        $liq->refresh();

        // La fecha SÍ se repara (el dato estaba mal)…
        $this->assertNotNull(DB::table('incomes')->where('user_id', $seller->user_id)->value('business_date'));
        // …pero la caja firmada no se recalcula: sus montos son un hecho.
        $this->assertEqualsWithDelta(1000, (float) $liq->real_to_deliver, 0.01);
        $this->assertEqualsWithDelta(0, (float) $liq->total_income, 0.01);
    }

    public function test_forzar_dias_cerrados_si_recalcula_la_caja_aprobada(): void
    {
        $seller = $this->makeSeller();
        $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 14:05:02');

        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');
        $liq->update(['status' => 'approved', 'real_to_deliver' => 1000, 'total_income' => 0]);

        $this->artisan(self::COMANDO, [
            '--credit' => [144878],
            '--apply' => true,
            '--forzar-dias-cerrados' => true,
        ])->assertSuccessful();

        $liq->refresh();
        $this->assertEqualsWithDelta(200, (float) $liq->total_income, 0.01);
    }

    public function test_sin_recalcular_ancla_la_fecha_pero_deja_la_caja_quieta(): void
    {
        $seller = $this->makeSeller();
        $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 14:05:02');

        $liq = app(LiquidationService::class)
            ->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');
        $liq->update(['total_income' => 0]);

        $this->artisan(self::COMANDO, [
            '--credit' => [144878],
            '--apply' => true,
            '--sin-recalcular' => true,
        ])->assertSuccessful();

        $this->assertNotNull(DB::table('incomes')->where('user_id', $seller->user_id)->value('business_date'));

        $liq->refresh();
        $this->assertEqualsWithDelta(0, (float) $liq->total_income, 0.01);
    }

    public function test_deja_intacto_el_movimiento_cuyo_usuario_no_es_vendedor(): void
    {
        // user_id de un admin: no tiene vendedor, así que no hay zona horaria
        // ni caja que recalcular. El comando informa y no adivina.
        $admin = User::factory()->create();
        $id = $this->movimientoHuerfano('incomes', $admin->id, 31687, 120, '2026-09-12 14:05:02');

        $this->artisan(self::COMANDO, ['--apply' => true])->assertSuccessful();

        $this->assertNull(DB::table('incomes')->where('id', $id)->value('business_date'));
    }

    public function test_es_idempotente(): void
    {
        $seller = $this->makeSeller();
        $id = $this->movimientoHuerfano('incomes', $seller->user_id, 144878, 200, '2026-09-12 14:05:02');

        app(LiquidationService::class)->getOrCreateLiquidation($seller->id, '2026-09-12', 'America/Lima');

        $this->artisan(self::COMANDO, ['--credit' => [144878], '--apply' => true])->assertSuccessful();
        $primera = DB::table('incomes')->where('id', $id)->first();

        // La segunda corrida ya no encuentra nada pendiente y no duplica efecto.
        $this->artisan(self::COMANDO, ['--credit' => [144878], '--apply' => true])->assertSuccessful();
        $segunda = DB::table('incomes')->where('id', $id)->first();

        $this->assertEquals($primera->business_date, $segunda->business_date);
        $this->assertEquals($primera->business_timestamp, $segunda->business_timestamp);

        $liq = Liquidation::where('seller_id', $seller->id)->first();
        $this->assertEqualsWithDelta(200, (float) $liq->total_income, 0.01);
    }
}
