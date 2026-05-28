<?php

namespace Tests\Feature\Commands;

use App\Models\Client;
use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixInstallmentsPaidAmountTest extends TestCase
{
    use RefreshDatabase;

    protected Seller $seller;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = Seller::factory()->create();
        $this->client = Client::factory()->create([
            'seller_id' => $this->seller->id,
            'geolocation' => '',
        ]);
    }

    /**
     * Crea un crédito con 3 cuotas y opcionalmente pagos aplicados.
     * Permite definir paid_amount cached vs payment_installments real
     * por separado para simular desincronización.
     *
     * @param array $instSpecs array de ['paid_cached' => float, 'pi_apps' => [['amount' => float]]]
     */
    protected function makeCreditWithDesync(array $instSpecs, array $creditOverrides = []): Credit
    {
        $credit = Credit::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'seller_id' => $this->seller->id,
            'credit_value' => 200,
            'total_interest' => 20,
            'total_amount' => 240,
            'remaining_amount' => 240,
            'number_installments' => count($instSpecs),
            'payment_frequency' => 'Semanal',
            'status' => 'Vigente',
        ], $creditOverrides));

        foreach ($instSpecs as $i => $spec) {
            $installment = Installment::create([
                'credit_id' => $credit->id,
                'quota_number' => $i + 1,
                'quota_amount' => $spec['quota_amount'] ?? 80,
                'paid_amount' => $spec['paid_cached'] ?? 0,
                'status' => $spec['status'] ?? 'Pendiente',
                'due_date' => Carbon::today()->addWeeks($i + 1)->toDateString(),
            ]);

            foreach (($spec['pi_apps'] ?? []) as $app) {
                $payment = Payment::create([
                    'credit_id' => $credit->id,
                    'user_id' => $this->seller->user_id,
                    'amount' => $app['amount'],
                    'unapplied_amount' => 0,
                    'payment_method' => 'Efectivo',
                    'payment_date' => Carbon::today()->toDateString(),
                    'business_date' => Carbon::today()->toDateString(),
                    'status' => 'Abonado',
                ]);
                PaymentInstallment::create([
                    'payment_id' => $payment->id,
                    'installment_id' => $installment->id,
                    'applied_amount' => $app['amount'],
                ]);
            }
        }

        return $credit->fresh();
    }

    public function test_detecta_y_fixea_paid_amount_subestimado(): void
    {
        // Reproduce el caso del crédito 656: paid_cached=$20 pero PI dice $80
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 80, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
            ['paid_cached' => 80, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
            ['paid_cached' => 20, 'status' => 'Pagado',
             'pi_apps' => [['amount' => 60], ['amount' => 20]]],
        ]);

        $this->artisan('installments:fix-paid-amount-cache', ['--credit-id' => $credit->id])
             ->assertExitCode(0);

        $third = $credit->installments()->where('quota_number', 3)->first();
        $this->assertEqualsWithDelta(80.0, (float) $third->paid_amount, 0.01, 'paid_amount debe quedar = $80 desde PI');
        $this->assertEquals('Pagado', $third->status);
    }

    public function test_dry_run_no_modifica_nada(): void
    {
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 20, 'status' => 'Pagado',
             'pi_apps' => [['amount' => 60], ['amount' => 20]]],
        ]);

        $this->artisan('installments:fix-paid-amount-cache', [
            '--credit-id' => $credit->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $inst = $credit->installments()->first();
        $this->assertEqualsWithDelta(20.0, (float) $inst->paid_amount, 0.01, 'dry-run no debe modificar paid_amount');
    }

    public function test_no_toca_installments_sincronizados(): void
    {
        // Todas las cuotas con cache y PI iguales
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 80, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
            ['paid_cached' => 40, 'status' => 'Parcial', 'pi_apps' => [['amount' => 40]]],
            ['paid_cached' => 0,  'status' => 'Pendiente'],
        ]);

        $this->artisan('installments:fix-paid-amount-cache', ['--credit-id' => $credit->id])
             ->expectsOutput('Installments desincronizados a procesar: 0')
             ->assertExitCode(0);
    }

    public function test_actualiza_status_a_parcial_cuando_cache_estaba_sobreestimado(): void
    {
        // Cache decía paid=80 (Pagado) pero PI real es solo 40 → debe quedar Parcial
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 80, 'status' => 'Pagado', 'pi_apps' => [['amount' => 40]]],
        ]);

        $this->artisan('installments:fix-paid-amount-cache', ['--credit-id' => $credit->id])
             ->assertExitCode(0);

        $inst = $credit->installments()->first();
        $this->assertEqualsWithDelta(40.0, (float) $inst->paid_amount, 0.01);
        $this->assertEquals('Parcial', $inst->status);
    }

    public function test_recalcula_credit_remaining_y_status_despues(): void
    {
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 80, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
            ['paid_cached' => 80, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
            ['paid_cached' => 20, 'status' => 'Pagado',
             'pi_apps' => [['amount' => 60], ['amount' => 20]]],
        ]);

        // remaining_amount queda mal antes del fix (calculado desde paid_amount viejo)
        $credit->remaining_amount = 60;
        $credit->save();

        $this->artisan('installments:fix-paid-amount-cache', ['--credit-id' => $credit->id])
             ->assertExitCode(0);

        $credit->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $credit->remaining_amount, 0.01, 'remaining debe llegar a 0 tras recalc');
        $this->assertEquals('Liquidado', $credit->status, 'Status debe transicionar a Liquidado');
    }

    public function test_skip_credit_recalc_no_actualiza_cabecera(): void
    {
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 20, 'status' => 'Pagado',
             'pi_apps' => [['amount' => 60], ['amount' => 20]]],
        ]);
        $credit->remaining_amount = 60;
        $credit->save();

        $this->artisan('installments:fix-paid-amount-cache', [
            '--credit-id' => $credit->id,
            '--skip-credit-recalc' => true,
        ])->assertExitCode(0);

        $credit->refresh();
        $this->assertEqualsWithDelta(60.0, (float) $credit->remaining_amount, 0.01, 'remaining queda igual');
        $inst = $credit->installments()->first();
        $this->assertEqualsWithDelta(80.0, (float) $inst->paid_amount, 0.01, 'pero el installment sí se sincronizó');
    }

    public function test_csv_se_genera_con_filas_para_dryrun(): void
    {
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 20, 'status' => 'Pagado',
             'pi_apps' => [['amount' => 60], ['amount' => 20]]],
        ]);

        $this->artisan('installments:fix-paid-amount-cache', [
            '--credit-id' => $credit->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $files = glob(storage_path('app/fix_installments_paid_DRYRUN_*.csv'));
        $this->assertNotEmpty($files);
        $latest = end($files);
        $content = file_get_contents($latest);
        $this->assertStringContainsString('paid_cached_before', $content);
        $this->assertStringContainsString('paid_real_from_pivot', $content);
        $this->assertStringContainsString('DRY-RUN', $content);
        @unlink($latest);
    }

    public function test_filtro_por_installment_id_procesa_solo_uno(): void
    {
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 20, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
            ['paid_cached' => 10, 'status' => 'Pagado', 'pi_apps' => [['amount' => 60]]],
        ]);

        $first = $credit->installments()->where('quota_number', 1)->first();

        $this->artisan('installments:fix-paid-amount-cache', ['--installment-id' => $first->id])
             ->assertExitCode(0);

        $first->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $first->paid_amount, 0.01);

        $second = $credit->installments()->where('quota_number', 2)->first();
        $this->assertEqualsWithDelta(10.0, (float) $second->paid_amount, 0.01, 'second installment NO debe modificarse');
    }

    public function test_filtro_por_seller_id(): void
    {
        $credit = $this->makeCreditWithDesync([
            ['paid_cached' => 20, 'status' => 'Pagado', 'pi_apps' => [['amount' => 80]]],
        ]);

        // otro seller con otro credit desincronizado
        $otherSeller = Seller::factory()->create();
        $otherClient = Client::factory()->create([
            'seller_id' => $otherSeller->id,
            'geolocation' => '',
        ]);
        $otherCredit = Credit::factory()->create([
            'client_id' => $otherClient->id,
            'seller_id' => $otherSeller->id,
            'credit_value' => 200,
            'total_interest' => 20,
            'total_amount' => 240,
            'remaining_amount' => 240,
            'number_installments' => 1,
            'payment_frequency' => 'Semanal',
            'status' => 'Vigente',
        ]);
        $otherInst = Installment::create([
            'credit_id' => $otherCredit->id,
            'quota_number' => 1,
            'quota_amount' => 80,
            'paid_amount' => 10, // desincronizado
            'status' => 'Pagado',
            'due_date' => Carbon::today()->toDateString(),
        ]);
        $otherPayment = Payment::create([
            'credit_id' => $otherCredit->id,
            'user_id' => $otherSeller->user_id,
            'amount' => 80,
            'unapplied_amount' => 0,
            'payment_method' => 'Efectivo',
            'payment_date' => Carbon::today()->toDateString(),
            'business_date' => Carbon::today()->toDateString(),
            'status' => 'Abonado',
        ]);
        PaymentInstallment::create([
            'payment_id' => $otherPayment->id,
            'installment_id' => $otherInst->id,
            'applied_amount' => 80,
        ]);

        $this->artisan('installments:fix-paid-amount-cache', ['--seller-id' => $this->seller->id])
             ->assertExitCode(0);

        $myInst = $credit->installments()->first();
        $this->assertEqualsWithDelta(80.0, (float) $myInst->paid_amount, 0.01, 'mi seller sí se actualiza');

        $otherInst->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $otherInst->paid_amount, 0.01, 'otro seller NO se toca');
    }
}
