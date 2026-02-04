<?php

namespace App\Console\Commands;

use Illuminate\Contracts\Console\Kernel;
use App\Models\Credit;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Script de Reconciliación de Pagos
 * Uso: php reconcile_payments.php [--fix] [--id=123]
 */

ini_set('memory_limit', '512M');

$isFix = in_array('--fix', $argv);
$onlyId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $onlyId = substr($arg, 5);
    }
}

echo "--- Script de Reconciliación de Pagos ---\n";
echo "Modo: " . ($isFix ? "EJECUCIÓN (FIX)" : "REPORTE (AUDIT)") . "\n";
if ($onlyId) echo "Filtrando por Crédito ID: $onlyId\n";
echo "----------------------------------------\n";

$results = [];
$totalCorrections = 0;

$query = Credit::query()->whereIn('status', ['Vigente', 'Atrasado', 'Liquidado']);
if ($onlyId) $query->where('id', $onlyId);

$query->chunk(50, function ($credits) use (&$results, &$totalCorrections, $isFix) {
    $paymentService = app(PaymentService::class);

    foreach ($credits as $credit) {
        $creditDiscrepancy = 0;
        $paymentsAffected = [];
        
        $payments = Payment::where('credit_id', $credit->id)
            ->where('status', '!=', 'Anulado')
            ->get();

        foreach ($payments as $payment) {
            $totalApplied = DB::table('payment_installments')
                ->where('payment_id', $payment->id)
                ->sum('applied_amount');
            
            $correctUnapplied = round($payment->amount - $totalApplied, 2);
            $currentUnapplied = (float) $payment->unapplied_amount;

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
                echo "Corrigiendo Crédito #{$credit->id} ({$credit->client->name}). Aplicando abonos...\n";
                $paymentService->reapplyPayments($credit->id);
            }
        }
    }
});

$reportFile = 'reconciliation_report_' . date('Ymd_His') . '.json';
file_put_contents($reportFile, json_encode($results, JSON_PRETTY_PRINT));

echo "----------------------------------------\n";
echo "Proceso finalizado.\n";
echo "Casos detectados: " . count($results) . "\n";
if ($isFix) echo "Pagos corregidos: $totalCorrections\n";
echo "Reporte detallado guardado en: $reportFile\n";
