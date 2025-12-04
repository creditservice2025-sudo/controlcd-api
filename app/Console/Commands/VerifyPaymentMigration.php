<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Installment;
use App\Models\Credit;

class VerifyPaymentMigration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:verify-migration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that all cached payments have been successfully migrated';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('=== Verifying Payment Migration ===');
        $this->info('');

        $issues = [];

        // Check 1: Payments without installment assignments
        $this->info('1. Checking for payments without installment assignments...');
        $orphanedPayments = Payment::whereNotIn('id', function ($query) {
            $query->select('payment_id')->from('payment_installments');
        })
            ->where('status', '!=', 'No pagado')
            ->where('amount', '>', 0)
            ->get();

        if ($orphanedPayments->count() > 0) {
            $issues[] = "Found {$orphanedPayments->count()} payments without installment assignments";
            $this->error("  ✗ Found {$orphanedPayments->count()} orphaned payments");

            foreach ($orphanedPayments->take(10) as $payment) {
                $this->line("    - Payment #{$payment->id}: ${$payment->amount} (Credit #{$payment->credit_id})");
            }

            if ($orphanedPayments->count() > 10) {
                $this->line("    ... and " . ($orphanedPayments->count() - 10) . " more");
            }
        } else {
            $this->info('  ✓ All payments have installment assignments');
        }

        // Check 2: Installments with paid_amount but wrong status
        $this->info('');
        $this->info('2. Checking for installments with inconsistent status...');
        $inconsistentInstallments = Installment::where(function ($query) {
            $query->where('paid_amount', '>', 0)
                ->where('paid_amount', '<', DB::raw('quota_amount'))
                ->where('status', '!=', 'Parcial');
        })
            ->orWhere(function ($query) {
                $query->where('paid_amount', '>=', DB::raw('quota_amount'))
                    ->where('status', '!=', 'Pagado');
            })
            ->get();

        if ($inconsistentInstallments->count() > 0) {
            $issues[] = "Found {$inconsistentInstallments->count()} installments with inconsistent status";
            $this->error("  ✗ Found {$inconsistentInstallments->count()} inconsistent installments");

            foreach ($inconsistentInstallments->take(10) as $installment) {
                $this->line(sprintf(
                    "    - Installment #%d (Credit #%d): paid $%s of $%s, status: %s",
                    $installment->id,
                    $installment->credit_id,
                    number_format($installment->paid_amount, 2),
                    number_format($installment->quota_amount, 2),
                    $installment->status
                ));
            }

            if ($inconsistentInstallments->count() > 10) {
                $this->line("    ... and " . ($inconsistentInstallments->count() - 10) . " more");
            }
        } else {
            $this->info('  ✓ All installments have consistent status');
        }

        // Check 3: Redis cache entries
        $this->info('');
        $this->info('3. Checking for remaining Redis cache entries...');

        $creditIds = Credit::pluck('id');
        $cachedCredits = 0;

        foreach ($creditIds as $creditId) {
            $cacheKey = "credit:{$creditId}:pending_payments";
            if (Cache::has($cacheKey)) {
                $cachedCredits++;
            }
        }

        if ($cachedCredits > 0) {
            $issues[] = "Found {$cachedCredits} credits still with Redis cache";
            $this->error("  ✗ Found {$cachedCredits} credits with cached payments");
        } else {
            $this->info('  ✓ No Redis cache entries found');
        }

        // Check 4: Migration statistics
        $this->info('');
        $this->info('4. Checking migration statistics...');

        $migratedPayments = Payment::where('migrated_from_cache', true)->get();

        if ($migratedPayments->count() > 0) {
            $totalMigratedAmount = $migratedPayments->sum('amount');
            $totalUnapplied = $migratedPayments->sum('unapplied_amount');

            $this->info("  ℹ Found {$migratedPayments->count()} payments migrated from cache");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total migrated payments', $migratedPayments->count()],
                    ['Total amount migrated', '$' . number_format($totalMigratedAmount, 2)],
                    ['Still unapplied', '$' . number_format($totalUnapplied, 2)],
                    ['Already applied', '$' . number_format($totalMigratedAmount - $totalUnapplied, 2)],
                ]
            );

            // Show sample of migrated payments
            $this->info('');
            $this->info('  Sample of migrated payments:');
            foreach ($migratedPayments->take(5) as $payment) {
                $this->line(sprintf(
                    "    - Payment #%d (Credit #%d): $%s total, $%s unapplied, migrated %s",
                    $payment->id,
                    $payment->credit_id,
                    number_format($payment->amount, 2),
                    number_format($payment->unapplied_amount, 2),
                    $payment->migrated_at ? $payment->migrated_at->format('Y-m-d H:i:s') : 'N/A'
                ));
            }

            if ($migratedPayments->count() > 5) {
                $this->line("    ... and " . ($migratedPayments->count() - 5) . " more");
            }
        } else {
            $this->info('  ℹ No migrated payments found (migration not run yet or no cached payments existed)');
        }

        // Check 5: Payment totals vs installment applications
        $this->info('');
        $this->info('5. Checking payment amounts vs applied amounts...');

        $mismatchedPayments = DB::select("
            SELECT
                p.id,
                p.credit_id,
                p.amount as payment_amount,
                COALESCE(SUM(pi.applied_amount), 0) as total_applied,
                p.amount - COALESCE(SUM(pi.applied_amount), 0) as difference
            FROM payments p
            LEFT JOIN payment_installments pi ON p.id = pi.payment_id
            WHERE p.status != 'No pagado' AND p.amount > 0
            GROUP BY p.id, p.credit_id, p.amount
            HAVING ABS(p.amount - COALESCE(SUM(pi.applied_amount), 0)) > 0.01
        ");

        if (count($mismatchedPayments) > 0) {
            $issues[] = "Found " . count($mismatchedPayments) . " payments with amount mismatches";
            $this->error("  ✗ Found " . count($mismatchedPayments) . " payments with mismatched amounts");

            foreach (array_slice($mismatchedPayments, 0, 10) as $payment) {
                $this->line(sprintf(
                    "    - Payment #%d (Credit #%d): paid $%s, applied $%s, diff $%s",
                    $payment->id,
                    $payment->credit_id,
                    number_format($payment->payment_amount, 2),
                    number_format($payment->total_applied, 2),
                    number_format($payment->difference, 2)
                ));
            }

            if (count($mismatchedPayments) > 10) {
                $this->line("    ... and " . (count($mismatchedPayments) - 10) . " more");
            }
        } else {
            $this->info('  ✓ All payment amounts match applied amounts');
        }

        // Summary
        $this->info('');
        $this->info('=== Verification Summary ===');

        if (count($issues) === 0) {
            $this->info('✓ All checks passed! Migration was successful.');
            return 0;
        } else {
            $this->error('✗ Found ' . count($issues) . ' issue(s):');
            foreach ($issues as $issue) {
                $this->error("  - {$issue}");
            }
            $this->info('');
            $this->warn('Please review and fix these issues before proceeding.');
            return 1;
        }
    }
}
