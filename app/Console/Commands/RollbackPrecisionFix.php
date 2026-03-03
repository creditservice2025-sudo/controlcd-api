<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RollbackPrecisionFix extends Command
{
    protected $signature = 'credits:rollback-precision-fix
                            {--dry-run : Show what would be changed without applying}
                            {--since= : Only rollback credits liquidated after this datetime (Y-m-d H:i:s). Default: today 00:00:00}';

    protected $description = 'Reverses the damage caused by running credits:fix-precision on corrupted credits. Restores remaining_amount and installment statuses from payment_installments records.';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $since  = $this->option('since')
            ?? now()->startOfDay()->toDateTimeString();

        $this->info($dryRun
            ? "--- DRY RUN MODE: No changes will be saved ---"
            : "--- STARTING ROLLBACK OF INCORRECT LIQUIDATIONS ---"
        );
        $this->info("Looking for credits incorrectly liquidated since: {$since}");
        $this->line('');

        // Find credits that were set to 'Liquidado' recently
        // but whose real debt (installments vs payments) is > $0.50
        $candidates = Credit::where('status', 'Liquidado')
            ->where('updated_at', '>=', $since)
            ->get();

        $this->info("Found {$candidates->count()} recently liquidated credits to evaluate.");
        $this->line('');

        $rolledBack  = 0;
        $skipped     = 0;
        $errors      = 0;

        foreach ($candidates as $credit) {
            // Calculate real remaining debt from installments vs payments
            $totalInstallments = (float) Installment::where('credit_id', $credit->id)->sum('quota_amount');
            $totalPaid         = (float) Payment::where('credit_id', $credit->id)->sum('amount');
            $realDebt          = round($totalInstallments - $totalPaid, 2);

            if ($realDebt <= 0.50) {
                // This was legitimately liquidated — skip it
                $skipped++;
                continue;
            }

            $this->warn("ROLLBACK needed → Credit #{$credit->id} | Real Debt: \${$realDebt} | DB says Liquidado");

            if (!$dryRun) {
                DB::beginTransaction();
                try {
                    // 1. Restore each installment: set paid_amount from actual payment_installments links
                    $installments = Installment::where('credit_id', $credit->id)->get();

                    foreach ($installments as $installment) {
                        // True amount applied to this installment from payment records
                        $truePaidAmount = (float) PaymentInstallment::where('installment_id', $installment->id)
                            ->sum('applied_amount');

                        $trueStatus = match(true) {
                            $truePaidAmount <= 0                                          => 'Pendiente',
                            $truePaidAmount >= round($installment->quota_amount, 2)       => 'Pagado',
                            default                                                       => 'Parcial',
                        };

                        $installment->paid_amount = round($truePaidAmount, 2);
                        $installment->status      = $trueStatus;
                        $installment->save();
                    }

                    // 2. Restore credit: set remaining_amount to real debt and status to Vigente
                    $credit->remaining_amount = $realDebt;
                    $credit->status           = 'Vigente';
                    $credit->save();

                    DB::commit();
                    $rolledBack++;
                    $this->info("  ✓ Restored Credit #{$credit->id} → remaining: \${$realDebt}, status: Vigente");
                    Log::info("RollbackPrecisionFix: Restored Credit #{$credit->id} → remaining_amount={$realDebt}");

                } catch (\Exception $e) {
                    DB::rollBack();
                    $errors++;
                    $this->error("  ✗ Error restoring Credit #{$credit->id}: " . $e->getMessage());
                    Log::error("RollbackPrecisionFix Error: Credit #{$credit->id} - " . $e->getMessage());
                }
            }
        }

        $this->line('');
        $this->info('=== SUMMARY ===');
        $this->info("Credits rolled back:          {$rolledBack}");
        $this->info("Credits skipped (OK/Legit):   {$skipped}");
        $this->error("Errors:                       {$errors}");

        if ($dryRun) {
            $this->warn('DRY RUN completed. Re-run without --dry-run to apply the rollback.');
        } else {
            $this->line('');
            $this->info('Rollback complete. Next steps:');
            $this->line('  1. Run: php artisan credits:sync-balances --dry-run  (verify counts look right now)');
            $this->line('  2. Run: php artisan credits:sync-balances            (fix remaining corrupted credits)');
            $this->line('  3. Run: php artisan credits:fix-precision --dry-run  (should show only ~7 credits)');
            $this->line('  4. Run: php artisan credits:fix-precision            (safely liquidate the real precision errors)');
        }

        $this->line('');
        $this->info('--- TASK COMPLETED ---');
        return $errors > 0 ? 1 : 0;
    }
}
