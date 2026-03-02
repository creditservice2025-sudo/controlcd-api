<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCreditBalances extends Command
{
    protected $signature = 'credits:sync-balances
                            {--dry-run : Show what would be changed without applying}
                            {--report= : Path to export CSV report (default: storage/app/credit_sync_report.csv)}
                            {--threshold=0.50 : Max difference in cents to classify as precision error vs real debt}';

    protected $description = 'Recalculates and repairs remaining_amount on credits where it is corrupted (e.g. = 0.00 while installments are still pending). Generates a detailed CSV report.';

    public function handle()
    {
        $dryRun    = $this->option('dry-run');
        $threshold = (float) $this->option('threshold');
        $reportPath = $this->option('report') ?: storage_path('app/credit_sync_report.csv');

        $this->info($dryRun
            ? "--- DRY RUN MODE: No changes will be saved ---"
            : "--- STARTING CREDIT BALANCE SYNC ---"
        );
        $this->info("Precision threshold: \${$threshold}");
        $this->line('');

        // Only look at non-liquidated credits where remaining_amount looks suspicious
        $credits = Credit::where('status', '!=', 'Liquidado')
            ->where('remaining_amount', '<=', 0.01)
            ->get();

        $this->info("Found {$credits->count()} candidate credits with remaining_amount <= 0.01");
        $this->line('');

        $precisionErrors   = [];   // True floating-point rounding issues
        $corruptedCredits  = [];   // Corrupted: real debt exists
        $alreadyOk         = [];   // Correctly at zero with no pending installments

        foreach ($credits as $credit) {
            $totalInstallments = (float) Installment::where('credit_id', $credit->id)->sum('quota_amount');
            $totalPaid         = (float) Payment::where('credit_id', $credit->id)->sum('amount');
            $realDebt          = round($totalInstallments - $totalPaid, 2);

            $hasPendingInstallments = Installment::where('credit_id', $credit->id)
                ->where('status', '!=', 'Pagado')
                ->exists();

            if (!$hasPendingInstallments && $realDebt <= $threshold) {
                // All installments paid, negligible real debt → OK to liquidate
                $alreadyOk[] = [
                    'id'              => $credit->id,
                    'total_amount'    => $credit->total_amount,
                    'db_remaining'    => $credit->remaining_amount,
                    'real_debt'       => $realDebt,
                    'classification'  => 'LISTO_PARA_LIQUIDAR',
                ];
            } elseif ($realDebt <= $threshold) {
                // Real debt is tiny — true floating-point precision error
                $precisionErrors[] = [
                    'id'              => $credit->id,
                    'total_amount'    => $credit->total_amount,
                    'db_remaining'    => $credit->remaining_amount,
                    'real_debt'       => $realDebt,
                    'classification'  => 'PRECISION_CENTAVOS',
                ];
            } else {
                // Real debt is significant — remaining_amount is corrupted
                $corruptedCredits[] = [
                    'id'              => $credit->id,
                    'total_amount'    => $credit->total_amount,
                    'db_remaining'    => $credit->remaining_amount,
                    'real_debt'       => $realDebt,
                    'classification'  => 'SALDO_CORRUPTO',
                ];

                if (!$dryRun) {
                    DB::beginTransaction();
                    try {
                        $credit->remaining_amount = $realDebt;
                        $credit->save();
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error("Error updating Credit #{$credit->id}: " . $e->getMessage());
                        Log::error("SyncCreditBalances: Credit #{$credit->id} - " . $e->getMessage());
                    }
                }
            }
        }

        // Summary
        $this->line('');
        $this->info('=== SUMMARY ===');
        $this->info('Saldo Corrupto (deuda real > $' . $threshold . '):  ' . count($corruptedCredits));
        $this->info('Error de Precisión (centavos):                     ' . count($precisionErrors));
        $this->info('Listos para Liquidar (sin cuotas pendientes):      ' . count($alreadyOk));

        // Generate CSV report
        $fp = fopen($reportPath, 'w');
        fputs($fp, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel UTF-8
        fputcsv($fp, ['ID Crédito', 'Total Crédito (BD)', 'Saldo BD (remaining)', 'Deuda Real (calculada)', 'Clasificación'], ';');

        foreach (array_merge($corruptedCredits, $precisionErrors, $alreadyOk) as $row) {
            fputcsv($fp, [
                $row['id'],
                number_format($row['total_amount'], 2),
                number_format($row['db_remaining'], 2),
                number_format($row['real_debt'], 2),
                $row['classification'],
            ], ';');
        }
        fclose($fp);

        $this->line('');
        $this->info("CSV report saved to: {$reportPath}");

        if ($dryRun) {
            $this->warn('DRY RUN completed. Re-run without --dry-run to apply the repairs.');
        } else {
            $this->info(count($corruptedCredits) . ' credits had their remaining_amount corrected.');
            $this->warn('Next step: Run `php artisan credits:fix-precision --dry-run` to confirm only true precision errors remain.');
        }

        $this->line('');
        $this->info('--- TASK COMPLETED ---');
        return 0;
    }
}
