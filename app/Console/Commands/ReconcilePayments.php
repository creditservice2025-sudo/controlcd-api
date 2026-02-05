<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcilePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reconcile:payments {--fix : Ejecutar la corrección en la base de datos} {--id= : Filtrar por Crédito ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detecta y corrige pagos desvinculados (Ghost Payments)';

    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        parent::__construct();
        $this->paymentService = $paymentService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isFix = $this->option('fix');
        $onlyId = $this->option('id');

        $this->info("--- Script de Reconciliación de Pagos ---");
        $this->info("Modo: " . ($isFix ? "EJECUCIÓN (FIX)" : "REPORTE (AUDIT)"));
        if ($onlyId) $this->info("Filtrando por Crédito ID: $onlyId");

        $results = [];
        $totalCorrections = 0;

        $query = Credit::query()->whereIn('status', ['Vigente', 'Atrasado', 'Liquidado']);
        if ($onlyId) $query->where('id', $onlyId);

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $query->chunk(50, function ($credits) use (&$results, &$totalCorrections, $isFix, $bar) {
            foreach ($credits as $credit) {
                $creditDiscrepancy = 0;
                $paymentsAffected = [];
                
                $payments = Payment::where('credit_id', $credit->id)
                    ->where('status', '!=', 'Anulado')
                    ->get();

                foreach ($payments as $payment) {
                    $totalApplied = DB::table('payment_installments')
                        ->where('payment_id', $payment->id)
                        ->whereNull('deleted_at')
                        ->sum('applied_amount');
                    
                    $correctUnapplied = round($payment->amount - $totalApplied, 2);
                    $currentUnapplied = (float) $payment->unapplied_amount;

                    if ($correctUnapplied < 0) {
                        Log::warning("Pago over-applied detectado: ID {$payment->id}, Applied: {$totalApplied}, Amount: {$payment->amount}");
                        continue;
                    }

                    if (abs($correctUnapplied - $currentUnapplied) > 0.01) {
                        $paymentsAffected[] = [
                            'payment_id' => $payment->id,
                            'amount' => $payment->amount,
                            'applied' => (float)$totalApplied,
                            'correct_unapplied' => $correctUnapplied,
                            'current_unapplied' => $currentUnapplied
                        ];
                        $creditDiscrepancy += ($correctUnapplied - $currentUnapplied);

                        if ($isFix) {
                            $payment->unapplied_amount = $correctUnapplied;
                            $payment->save();
                            $totalCorrections++;
                        }
                    }
                }

                if (!empty($paymentsAffected)) {
                    $results[] = [
                        'credit_id' => $credit->id,
                        'client' => $credit->client->name ?? 'Unknown',
                        'discrepancy' => round($creditDiscrepancy, 2),
                        'payments' => $paymentsAffected
                    ];

                    if ($isFix) {
                        $this->paymentService->reapplyPayments($credit->id);
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $reportFile = 'reconciliation_report_' . date('Ymd_His') . '.json';
        file_put_contents(base_path($reportFile), json_encode($results, JSON_PRETTY_PRINT));

        $this->info("Proceso finalizado.");
        $this->line("Casos detectados: " . count($results));
        if ($isFix) $this->info("Pagos corregidos: $totalCorrections");
        $this->info("Reporte detallado guardado en: $reportFile");

        return 0;
    }
}
