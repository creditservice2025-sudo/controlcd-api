<?php

namespace Tests\Feature\Commands;

use App\Models\Client;
use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixBrokenCreditTotalsTest extends TestCase
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

    protected function makeBrokenCredit(array $payments = []): Credit
    {
        $credit = Credit::factory()->create([
            'client_id' => $this->client->id,
            'seller_id' => $this->seller->id,
            'credit_value' => 300,
            'total_interest' => 20,
            'total_amount' => 0, // ← bug histórico
            'remaining_amount' => 0,
            'number_installments' => 3,
            'payment_frequency' => 'Semanal',
            'status' => 'Vigente',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            Installment::create([
                'credit_id' => $credit->id,
                'quota_number' => $i,
                'quota_amount' => 120,
                'paid_amount' => 0,
                'due_date' => Carbon::today()->addWeeks($i)->toDateString(),
                'status' => 'Pendiente',
            ]);
        }

        foreach ($payments as $pay) {
            Payment::create(array_merge([
                'credit_id' => $credit->id,
                'user_id' => $this->seller->user_id,
                'amount' => 80,
                'unapplied_amount' => 80,
                'payment_method' => 'Efectivo',
                'payment_date' => Carbon::today()->toDateString(),
                'business_date' => Carbon::today()->toDateString(),
                'status' => 'Abonado',
            ], $pay));
        }

        return $credit->fresh();
    }

    public function test_dry_run_no_modifica_la_base_de_datos(): void
    {
        $credit = $this->makeBrokenCredit();

        $this->artisan('credits:fix-broken-totals', ['--credit-id' => $credit->id, '--dry-run' => true])
             ->assertExitCode(0);

        $credit->refresh();
        $this->assertEquals(0.00, (float) $credit->total_amount, 'dry-run no debe modificar total_amount');
    }

    public function test_dry_run_genera_csv_con_prefijo_DRYRUN(): void
    {
        $credit = $this->makeBrokenCredit();

        $this->artisan('credits:fix-broken-totals', ['--credit-id' => $credit->id, '--dry-run' => true])
             ->assertExitCode(0);

        $files = glob(storage_path('app/fix_broken_totals_DRYRUN_*.csv'));
        $this->assertNotEmpty($files, 'Debe generar CSV con prefijo DRYRUN');
        $latest = end($files);
        $content = file_get_contents($latest);
        $this->assertStringContainsString((string) $credit->id, $content);
        $this->assertStringContainsString('DRY-RUN', $content);
        @unlink($latest);
    }

    public function test_aplica_fix_seteando_total_amount_correcto(): void
    {
        $credit = $this->makeBrokenCredit();

        $this->artisan('credits:fix-broken-totals', ['--credit-id' => $credit->id])
             ->assertExitCode(0);

        $credit->refresh();
        $this->assertEqualsWithDelta(360.0, (float) $credit->total_amount, 0.01, 'total_amount = 300 + 20% = 360');
        $this->assertEqualsWithDelta(360.0, (float) $credit->remaining_amount, 0.01, 'remaining = sum installments pendientes');
    }

    public function test_reaplica_payments_cuando_stack_cubre_quota_completa(): void
    {
        $credit = $this->makeBrokenCredit([
            ['amount' => 120, 'unapplied_amount' => 120],
            ['amount' => 20, 'unapplied_amount' => 20],
        ]);

        $this->artisan('credits:fix-broken-totals', ['--credit-id' => $credit->id])
             ->assertExitCode(0);

        $credit->refresh();
        $sumPaid = (float) $credit->installments()->whereNull('deleted_at')->sum('paid_amount');
        $this->assertEqualsWithDelta(120.0, $sumPaid, 0.01, 'Cubre cuota 1, se detiene en cuota 2 por falta de stack');
        // El cliente puso 140 (120 + 20) sobre una deuda de 360, así que debe 220.
        // Los 20 quedan en unapplied_amount porque no alcanzan a cubrir la cuota
        // de 120, pero son plata recibida y bajan el saldo igual. Este assert
        // esperaba 240, que era cobrarle de nuevo el abono que ya había hecho.
        $this->assertEqualsWithDelta(220.0, (float) $credit->remaining_amount, 0.01);
    }

    public function test_promueve_a_liquidado_cuando_pagos_cubren_todo(): void
    {
        $credit = $this->makeBrokenCredit([
            ['amount' => 120, 'unapplied_amount' => 120],
            ['amount' => 120, 'unapplied_amount' => 120],
            ['amount' => 120, 'unapplied_amount' => 120],
        ]);

        $this->artisan('credits:fix-broken-totals', ['--credit-id' => $credit->id])
             ->assertExitCode(0);

        $credit->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $credit->remaining_amount, 0.01);
        $this->assertEquals('Liquidado', $credit->status);
    }

    public function test_no_toca_creditos_con_total_amount_correcto(): void
    {
        $healthy = Credit::factory()->create([
            'client_id' => $this->client->id,
            'seller_id' => $this->seller->id,
            'credit_value' => 300,
            'total_interest' => 20,
            'total_amount' => 360,
            'remaining_amount' => 360,
            'number_installments' => 3,
            'payment_frequency' => 'Semanal',
            'status' => 'Vigente',
        ]);

        $this->artisan('credits:fix-broken-totals', ['--seller-id' => $this->seller->id])
             ->assertExitCode(0);

        $healthy->refresh();
        $this->assertEqualsWithDelta(360.0, (float) $healthy->total_amount, 0.01);
    }

    public function test_skip_reapply_no_reaplica_payments(): void
    {
        $credit = $this->makeBrokenCredit([
            ['amount' => 120, 'unapplied_amount' => 120],
        ]);

        $this->artisan('credits:fix-broken-totals', [
            '--credit-id' => $credit->id,
            '--skip-reapply' => true,
        ])->assertExitCode(0);

        $credit->refresh();
        $this->assertEqualsWithDelta(360.0, (float) $credit->total_amount, 0.01, 'total_amount sí se arregla');
        $sumPaid = (float) $credit->installments()->whereNull('deleted_at')->sum('paid_amount');
        $this->assertEqualsWithDelta(0.0, $sumPaid, 0.01, 'pero payments NO se reaplican');
    }

    public function test_limit_procesa_solo_n_creditos(): void
    {
        $c1 = $this->makeBrokenCredit();
        $c2 = $this->makeBrokenCredit();
        $c3 = $this->makeBrokenCredit();

        $this->artisan('credits:fix-broken-totals', [
            '--seller-id' => $this->seller->id,
            '--limit' => 2,
        ])->assertExitCode(0);

        $c1->refresh(); $c2->refresh(); $c3->refresh();
        $fixed = collect([$c1, $c2, $c3])->filter(fn($c) => (float) $c->total_amount > 0)->count();
        $this->assertEquals(2, $fixed, 'Solo 2 de los 3 deben quedar arreglados');
    }

    public function test_fix_de_createCreditForNewClient_evita_nuevos_creditos_rotos(): void
    {
        // Garantía de que el fix raíz queda activo: crear vía el flujo público
        // ya NO produce créditos con total_amount = 0.
        $service = app(\App\Services\ClientService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('createCreditForNewClient');
        $method->setAccessible(true);

        $credit = $method->invoke($service, $this->client, [
            'credit_value' => 500,
            'interest_rate' => 20,
            'number_installments' => 5,
            'payment_frequency' => 'Semanal',
            'micro_insurance_percentage' => 0,
            'micro_insurance_amount' => 0,
        ], null);

        $credit = $credit->fresh();
        $this->assertEqualsWithDelta(600.0, (float) $credit->total_amount, 0.01, 'createCreditForNewClient ahora setea total_amount');
        $this->assertEqualsWithDelta(600.0, (float) $credit->remaining_amount, 0.01);
    }
}
