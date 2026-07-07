<?php

namespace Tests\Feature\Commands;

use App\Models\City;
use App\Models\Client;
use App\Models\Country;
use App\Models\Credit;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El comando de normalización debe: (1) detectar la liquidación vieja que restó
 * el irrecuperable de un crédito que SIGUE incobrable, (2) con --apply crear un
 * ingreso de ajuste por ese monto en el día abierto del vendedor, (3) ser
 * idempotente (no duplicar en una segunda corrida).
 */
class NormalizeIrrecoverableTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $country = Country::factory()->create(['name' => 'Perú']);
        $city = City::factory()->create(['country_id' => $country->id]);
        $seller = Seller::factory()->create(['city_id' => $city->id]);
        $user = User::find($seller->user_id);
        $client = Client::factory()->create(['geolocation' => '0,0']);

        // Crédito que SIGUE en Cartera Irrecuperable, marcado el 2026-06-01.
        $credit = Credit::factory()->create([
            'seller_id'         => $seller->id,
            'client_id'         => $client->id,
            'status'            => 'Cartera Irrecuperable',
            'payment_frequency' => 'Diaria',
        ]);
        Installment::factory()->create([
            'credit_id'    => $credit->id,
            'quota_amount' => 100.00,
            'status'       => 'Pendiente',
        ]);
        // Fijar updated_at del crédito al día del marcado (para la traza directa).
        DB::table('credits')->where('id', $credit->id)->update(['updated_at' => '2026-06-01 10:00:00']);

        // Liquidación vieja APROBADA que RESTÓ el irrec (real_to_deliver = -100).
        $liq = Liquidation::create([
            'seller_id' => $seller->id,
            'date' => '2026-06-01',
            'status' => 'approved',
            'currency' => 'PEN', 'collection_target' => 0,
            'initial_cash' => 0, 'total_collected' => 0, 'total_income' => 0, 'poliza' => 0,
            'base_delivered' => 0, 'total_expenses' => 0, 'new_credits' => 0,
            'renewal_disbursed_total' => 0,
            'irrecoverable_credits_amount' => 100.00,
            'real_to_deliver' => -100.00,  // = (0) - 100  => SÍ restó
            'shortage' => 0, 'surplus' => 0, 'cash_delivered' => 0,
        ]);

        return compact('seller', 'user', 'credit', 'liq');
    }

    public function test_apply_crea_el_ingreso_de_reversa_y_es_idempotente(): void
    {
        ['seller' => $seller, 'user' => $user] = $this->scenario();

        $this->artisan('liquidations:normalize-irrecoverable --apply --force')->assertExitCode(0);

        $incomes = Income::where('user_id', $user->id)
            ->where('description', 'like', '%AJUSTE-NORM-IRREC%')->get();

        $this->assertCount(1, $incomes, 'Debe crear exactamente 1 ingreso de ajuste');
        $this->assertEquals(100.00, (float) $incomes->first()->value);
        $this->assertStringContainsString('Cartera Irrecuperable', $incomes->first()->description);

        // Idempotencia: segunda corrida NO duplica.
        $this->artisan('liquidations:normalize-irrecoverable --apply --force')->assertExitCode(0);
        $this->assertCount(
            1,
            Income::where('user_id', $user->id)->where('description', 'like', '%AJUSTE-NORM-IRREC%')->get(),
            'La segunda corrida no debe duplicar el ingreso'
        );
    }

    public function test_dry_run_no_crea_nada(): void
    {
        ['user' => $user] = $this->scenario();

        $this->artisan('liquidations:normalize-irrecoverable')->assertExitCode(0);

        $this->assertSame(0, Income::where('user_id', $user->id)->count(), 'Dry-run no debe crear ingresos');
    }
}
