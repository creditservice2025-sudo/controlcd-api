<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Installment;
use App\Models\PaymentInstallment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigratePaymentInstallments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:migrate-installments {--dry-run : Run without making changes} {--credit= : Migrate only specific credit ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy payments to create payment_installments records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $creditId = $this->option('credit');

        $this->info('Starting payment installments migration...');
        $this->info($dryRun ? 'DRY RUN MODE - No changes will be made' : 'LIVE MODE - Changes will be saved');

        // Find payments without payment_installments (including soft-deleted)
        $paymentsQuery = Payment::withTrashed()
            ->leftJoin('payment_installments', 'payments.id', '=', 'payment_installments.payment_id')
            ->whereNull('payment_installments.id')
            ->where('payments.amount', '>', 0) // Skip $0 payments
            ->select('payments.*');

        if ($creditId) {
            $paymentsQuery->where('payments.credit_id', $creditId);
            $this->info("Filtering by credit ID: $creditId");
        }

        $payments = $paymentsQuery->get();

        // Debug: Show the query
        if ($this->option('verbose') || $creditId) {
            $this->info("SQL: " . $paymentsQuery->toSql());
            $this->info("Bindings: " . json_encode($paymentsQuery->getBindings()));
        }

        $this->info("Found {$payments->count()} payments without installment records");

        if ($payments->isEmpty()) {
            $this->info('No payments to migrate!');
            return 0;
        }

        $bar = $this->output->createProgressBar($payments->count());
        $bar->start();

        $migratedCount = 0;
        $errorCount = 0;

        foreach ($payments as $payment) {
            try {
                if (!$dryRun) {
                    DB::beginTransaction();
                }

                $result = $this->migratePayment($payment, $dryRun);

                if ($result) {
                    $migratedCount++;
                    if (!$dryRun) {
                        DB::commit();
                    }
                } else {
                    $errorCount++;
                    if (!$dryRun) {
                        DB::rollBack();
                    }
                }
            } catch (\Exception $e) {
                $errorCount++;
                if (!$dryRun) {
                    DB::rollBack();
                }
                Log::error("Error migrating payment {$payment->id}: " . $e->getMessage());
                $this->error("\nError migrating payment {$payment->id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Migration completed!");
        $this->info("Successfully migrated: $migratedCount");
        $this->info("Errors: $errorCount");

        if ($dryRun) {
            $this->warn('This was a DRY RUN. No changes were made to the database.');
            $this->info('Run without --dry-run to apply changes.');
        }

        return 0;
    }

    /**
     * Migrate a single payment by creating payment_installments records
     */
    private function migratePayment(Payment $payment, bool $dryRun): bool
    {
        // Get pending installments at the time of payment
        $installments = Installment::where('credit_id', $payment->credit_id)
            ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado', 'Pagado'])
            ->where('due_date', '<=', $payment->payment_date)
            ->orderBy('due_date', 'asc')
            ->get();

        if ($installments->isEmpty()) {
            // If no installments found by due date, get all installments ordered by quota_number
            $installments = Installment::where('credit_id', $payment->credit_id)
                ->orderBy('quota_number', 'asc')
                ->get();
        }

        if ($installments->isEmpty()) {
            $this->warn("\nNo installments found for payment {$payment->id} (credit {$payment->credit_id})");
            return false;
        }

        $remainingAmount = (float) $payment->amount;
        $recordsCreated = 0;

        foreach ($installments as $installment) {
            if ($remainingAmount <= 0) {
                break;
            }

            // Calculate how much was already paid on this installment from OTHER payments
            $alreadyPaid = PaymentInstallment::where('installment_id', $installment->id)
                ->where('payment_id', '!=', $payment->id)
                ->sum('applied_amount');

            $quotaAmount = (float) $installment->quota_amount;
            $pendingAmount = $quotaAmount - $alreadyPaid;

            if ($pendingAmount <= 0.001) {
                continue; // This installment is already fully paid
            }

            $toApply = min($pendingAmount, $remainingAmount);
            $toApply = round($toApply, 2);

            if ($toApply <= 0) {
                continue;
            }

            if (!$dryRun) {
                PaymentInstallment::create([
                    'payment_id' => $payment->id,
                    'installment_id' => $installment->id,
                    'applied_amount' => $toApply,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ]);
            }

            $recordsCreated++;
            $remainingAmount -= $toApply;

            if ($dryRun) {
                $this->line("\n  Would create: Payment {$payment->id} -> Installment {$installment->id} (Quota #{$installment->quota_number}): \${$toApply}");
            }
        }

        if ($recordsCreated === 0) {
            $this->warn("\nNo records created for payment {$payment->id}");
            return false;
        }

        return true;
    }
}
