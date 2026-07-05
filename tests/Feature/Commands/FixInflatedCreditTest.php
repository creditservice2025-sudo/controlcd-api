<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica el comando `credits:fix-inflated`, que corrige un crédito cuya
 * cadena (total_amount + cuotas + pagos + distribución) quedó inflada por un
 * factor uniforme respecto a credit_value (caso real #5435: factor 100).
 *
 * Corre contra la BD de testing (controlcd_testing) — NO toca producción.
 */
class FixInflatedCreditTest extends TestCase
{
    use RefreshDatabase;

    private const TS = '2026-02-16 21:55:00';

    /**
     * Crea un crédito inflado ×$factor: credit_value 300.000 (correcto 360.000)
     * pero total_amount / cuotas / pago / distribución a la escala inflada.
     * Devuelve el id del crédito.
     */
    private function makeInflatedCredit(int $factor = 100): int
    {
        $country = Country::factory()->create(['name' => 'Colombia']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => ['latitude' => 0, 'longitude' => 0],
        ]);

        $creditValue = 300000;
        $interest = 20;
        $correctTotal = $creditValue * (1 + $interest / 100); // 360.000
        $inflatedTotal = $correctTotal * $factor;             // 36.000.000
        $quota = $inflatedTotal / 4;                          // 9.000.000

        $credit = Credit::factory()->create([
            'seller_id' => $seller->id,
            'client_id' => $client->id,
            'credit_value' => $creditValue,
            'total_interest' => $interest,
            'total_amount' => $inflatedTotal,
            'remaining_amount' => 0,
            'number_installments' => 4,
            'payment_frequency' => 'Semanal',
            'status' => 'Liquidado',
        ]);

        $installmentIds = [];
        for ($n = 1; $n <= 4; $n++) {
            $installmentIds[] = DB::table('installments')->insertGetId([
                'credit_id' => $credit->id,
                'quota_number' => $n,
                'due_date' => date('Y-m-d', strtotime("2026-02-16 +{$n} week")),
                'quota_amount' => $quota,
                'paid_amount' => $quota,
                'status' => 'Pagado',
                'created_at' => self::TS,
                'updated_at' => self::TS,
            ]);
        }

        $paymentId = DB::table('payments')->insertGetId([
            'credit_id' => $credit->id,
            'payment_date' => '2026-02-16',
            'amount' => $inflatedTotal,
            'unapplied_amount' => 0,
            'status' => 'Pagado',
            'payment_method' => 'Efectivo',
            'business_date' => '2026-02-16',
            'created_at' => self::TS,
            'updated_at' => self::TS,
        ]);

        foreach ($installmentIds as $iid) {
            DB::table('payment_installments')->insert([
                'payment_id' => $paymentId,
                'installment_id' => $iid,
                'applied_amount' => $quota,
                'created_at' => self::TS,
                'updated_at' => self::TS,
            ]);
        }

        return $credit->id;
    }

    public function test_corrige_la_cadena_completa_del_credito_inflado(): void
    {
        $id = $this->makeInflatedCredit(100);

        $this->artisan('credits:fix-inflated', ['credit' => $id, '--apply' => true])->assertExitCode(0);

        // Crédito: total_amount corregido a 360.000, remaining 0, sigue Liquidado.
        $credit = Credit::find($id);
        $this->assertEqualsWithDelta(360000, (float) $credit->total_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $credit->remaining_amount, 0.01);
        $this->assertSame('Liquidado', $credit->status);

        // Cuotas: 90.000 cada una (quota y paid).
        foreach (DB::table('installments')->where('credit_id', $id)->get() as $i) {
            $this->assertEqualsWithDelta(90000, (float) $i->quota_amount, 0.01);
            $this->assertEqualsWithDelta(90000, (float) $i->paid_amount, 0.01);
        }

        // Pago: 360.000. Distribución: 90.000 por cuota.
        $payment = DB::table('payments')->where('credit_id', $id)->first();
        $this->assertEqualsWithDelta(360000, (float) $payment->amount, 0.01);
        foreach (DB::table('payment_installments')->where('payment_id', $payment->id)->get() as $pi) {
            $this->assertEqualsWithDelta(90000, (float) $pi->applied_amount, 0.01);
        }

        // La suma de pagos ahora coincide con la deuda real: caja reconcilia.
        $sumPaid = (float) DB::table('payments')->where('credit_id', $id)->whereNull('deleted_at')->sum('amount');
        $this->assertEqualsWithDelta(360000, $sumPaid, 0.01);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $id = $this->makeInflatedCredit(100);

        // Sin --apply: no debe cambiar nada.
        $this->artisan('credits:fix-inflated', ['credit' => $id])->assertExitCode(0);

        $credit = Credit::find($id);
        $this->assertEqualsWithDelta(36000000, (float) $credit->total_amount, 0.01);
    }

    public function test_es_idempotente(): void
    {
        $id = $this->makeInflatedCredit(100);

        $this->artisan('credits:fix-inflated', ['credit' => $id, '--apply' => true])->assertExitCode(0);
        // Segunda corrida: ya consistente, no vuelve a dividir.
        $this->artisan('credits:fix-inflated', ['credit' => $id, '--apply' => true])->assertExitCode(0);

        $credit = Credit::find($id);
        $this->assertEqualsWithDelta(360000, (float) $credit->total_amount, 0.01);
    }

    public function test_no_toca_un_credito_ya_consistente(): void
    {
        $country = Country::factory()->create(['name' => 'Colombia']);
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
            'total_amount' => 360000, // ya correcto
            'payment_frequency' => 'Semanal',
        ]);

        $this->artisan('credits:fix-inflated', ['credit' => $credit->id, '--apply' => true])->assertExitCode(0);

        $this->assertEqualsWithDelta(360000, (float) Credit::find($credit->id)->total_amount, 0.01);
    }
}
