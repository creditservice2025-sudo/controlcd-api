<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Credit;
use App\Models\Payment;
use App\Models\Installment;
use App\Models\PaymentInstallment;

class MigrateCachedPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:migrate-cached {--dry-run : Run without making changes} {--credit= : Migrate specific credit ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate cached partial payments from Redis to new unapplied_amount structure';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check if unapplied_amount column exists
        if (!Schema::hasColumn('payments', 'unapplied_amount')) {
            $this->error('ERROR: The "unapplied_amount" column does not exist in the payments table.');
            $this->error('Please run migrations first: php artisan migrate');
            return 1;
        }

        $dryRun = $this->option('dry-run');
        $specificCredit = $this->option('credit');

        if ($dryRun) {
            $this->warn('=== DRY RUN MODE - No changes will be made ===');
        } else {
            $this->info('=== LIVE MIGRATION MODE ===');
            $this->warn('This will:');
            $this->warn('  1. Set unapplied_amount for existing cached payments');
            $this->warn('  2. Clear Redis cache for migrated credits');
            $this->info('');
            if (!$this->confirm('Continue?')) {
                $this->info('Migration cancelled.');
                return 1;
            }
        }

        $this->info('');
        $this->info('Starting cached payments migration to unapplied_amount structure...');
        $this->info('');

        // Get credit IDs to process
        if ($specificCredit) {
            $creditIds = collect([$specificCredit]);
            $this->info("Processing specific credit: #{$specificCredit}");
        } else {
            $creditIds = Credit::pluck('id');
            $this->info("Scanning {$creditIds->count()} credits...");
        }

        $migratedCount = 0;
        $paymentsUpdated = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $errors = [];

        $bar = $this->output->createProgressBar($creditIds->count());
        $bar->start();

        foreach ($creditIds as $creditId) {
            try {
                $result = $this->migrateCreditPayments($creditId, $dryRun);

                if ($result['migrated'] > 0) {
                    $migratedCount++;
                    $paymentsUpdated += $result['payments_updated'];
                } else {
                    $skippedCount++;
                }

            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = [
                    'credit_id' => $creditId,
                    'error' => $e->getMessage()
                ];
                Log::error("Migration error for credit #{$creditId}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->info('');
        $this->info('');

        // Display summary
        $this->info('=== Migration Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Credits migrated', $migratedCount],
                ['Payments updated', $paymentsUpdated],
                ['Credits skipped (no cache)', $skippedCount],
                ['Errors', $errorCount],
            ]
        );

        // Show errors if any
        if ($errorCount > 0) {
            $this->error('');
            $this->error('=== Errors Encountered ===');
            foreach ($errors as $error) {
                $this->error("Credit #{$error['credit_id']}: {$error['error']}");
            }
        }

        $this->info('');

        if (!$dryRun && $migratedCount > 0) {
            $this->info('✓ Migration completed successfully!');
            $this->info('');
            $this->warn('IMPORTANT: Verify the migration with:');
            $this->line('  php artisan payments:verify-migration');
        } elseif ($dryRun) {
            $this->warn('DRY RUN completed. Run without --dry-run to apply changes.');
        } else {
            $this->info('No payments to migrate.');
        }

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Migrate cached payments for a specific credit to unapplied_amount structure
     *
     * @param int $creditId
     * @param bool $dryRun
     * @return array
     */
    private function migrateCreditPayments($creditId, $dryRun)
    {
        $cacheKey = "credit:{$creditId}:pending_payments";
        $cachePaymentsKey = "credit:{$creditId}:pending_payments_list";

        $accumulated = Cache::get($cacheKey);
        $pendingPayments = Cache::get($cachePaymentsKey);

        // Skip if no cached data
        if (!$accumulated || !$pendingPayments || !is_array($pendingPayments) || empty($pendingPayments)) {
            return ['migrated' => 0, 'payments_updated' => 0, 'skipped' => true];
        }

        // In dry-run mode, just count
        if ($dryRun) {
            $this->line('');
            $this->line(sprintf(
                "  Would migrate Credit #%d: %d payments, $%s accumulated",
                $creditId,
                count($pendingPayments),
                number_format($accumulated, 2)
            ));
            return ['migrated' => 1, 'payments_updated' => count($pendingPayments), 'skipped' => false];
        }

        // Actual migration
        DB::beginTransaction();

        try {
            $credit = Credit::with('client')->findOrFail($creditId);
            $paymentsUpdated = 0;

            // For each cached payment, set its unapplied_amount and mark as migrated
            foreach ($pendingPayments as $pendingPayment) {
                $payment = Payment::find($pendingPayment['payment_id']);

                if (!$payment) {
                    Log::warning("Payment #{$pendingPayment['payment_id']} not found for credit #{$creditId}, skipping");
                    continue;
                }

                // Set unapplied_amount to the full payment amount
                // The new system will handle applying these to installments using FIFO logic
                $payment->unapplied_amount = $pendingPayment['amount'];

                // Mark as migrated from cache for tracking and analysis
                $payment->migrated_from_cache = true;
                $payment->migrated_at = now();

                $payment->save();

                $paymentsUpdated++;

                Log::info(sprintf(
                    "Migrated Payment #%d: $%s set as unapplied (from cache)",
                    $payment->id,
                    number_format($pendingPayment['amount'], 2)
                ));
            }

            // Clear cache
            Cache::forget($cacheKey);
            Cache::forget($cachePaymentsKey);

            // Log success
            Log::info(sprintf(
                "Migrated credit #%d: %d payments updated, $%s total unapplied",
                $creditId,
                $paymentsUpdated,
                number_format($accumulated, 2)
            ));

            DB::commit();

            return ['migrated' => 1, 'payments_updated' => $paymentsUpdated, 'skipped' => false];

        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Failed to migrate: " . $e->getMessage());
        }
    }
}
