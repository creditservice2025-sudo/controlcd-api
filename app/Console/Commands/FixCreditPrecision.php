<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Models\Installment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixCreditPrecision extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credits:fix-precision {--dry-run : Only show what would be changed without applying changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identifies and liquidates credits with negligible balances due to floating-point precision issues.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? "--- DRY RUN MODE: No changes will be saved ---" : "--- STARTING CREDIT PRECISION FIX ---");

        // We look for credits that stay "Vigente" or other active status but have a very small remaining amount (or are overpaid)
        $credits = Credit::where('status', '!=', 'Liquidado')
            ->where('remaining_amount', '<=', 0.01)
            ->get();

        $this->info("Found " . $credits->count() . " credits with balance <= 0.01");
        foreach ($credits as $credit) {
            $this->warn("Credit ID: {$credit->id} | Balance: {$credit->remaining_amount} | Status: {$credit->status}");

            if (!$dryRun) {
                try {
                    DB::beginTransaction();

                    // Process unapplied payments (FIFO)
                    $unappliedPayments = \App\Models\Payment::where('credit_id', $credit->id)
                        ->where('unapplied_amount', '>', 0)
                        ->orderBy('created_at', 'asc')
                        ->get();

                    // Update all pending/partial installments
                    $installments = Installment::where('credit_id', $credit->id)
                        ->where('status', '!=', 'Pagado')
                        ->orderBy('due_date', 'asc')
                        ->get();

                    foreach ($installments as $installment) {
                        $targetAmount = round($installment->quota_amount - $installment->paid_amount, 2);

                        // If there is unapplied money, try to apply it to justify the closing
                        foreach ($unappliedPayments as $payment) {
                            if ($targetAmount <= 0) break;
                            if ($payment->unapplied_amount <= 0) continue;

                            $toTake = min($payment->unapplied_amount, $targetAmount);

                            \App\Models\PaymentInstallment::create([
                                'payment_id' => $payment->id,
                                'installment_id' => $installment->id,
                                'applied_amount' => $toTake,
                                'created_at' => $payment->business_timestamp ?? $payment->created_at,
                                'updated_at' => now()
                            ]);

                            $installment->paid_amount += $toTake;
                            $payment->unapplied_amount -= $toTake;
                            $payment->save();

                            $targetAmount -= $toTake;
                        }

                        // Force close installment due to precision limits
                        $installment->paid_amount = $installment->quota_amount;
                        $installment->status = 'Pagado';
                        $installment->save();
                    }

                    // Any remaining unapplied money is zeroed out since the credit is liquidated
                    // This prevents floating unapplied money on closed credits
                    foreach ($unappliedPayments as $payment) {
                        if ($payment->unapplied_amount > 0) {
                            $payment->unapplied_amount = 0;
                            $payment->save();
                        }
                    }

                    // Liquidate Credit
                    $credit->remaining_amount = 0;
                    $credit->status = 'Liquidado';
                    $credit->save();

                    DB::commit();
                    $this->info("Successfully liquidated Credit #{$credit->id} and resolved unapplied payments");
                    Log::info("Precision Fix: Liquidated Credit #{$credit->id} (Linked unapplied payments)");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("Failed to liquidate Credit #{$credit->id}: " . $e->getMessage());
                    Log::error("Precision Fix Error: Credit #{$credit->id} - " . $e->getMessage());
                }
            }
        }

        // Secondary check: Credits with NO pending installments but NOT status 'Liquidado'
        $inactiveCredits = Credit::where('status', '!=', 'Liquidado')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('installments')
                    ->whereColumn('installments.credit_id', 'credits.id')
                    ->where('installments.status', '!=', 'Pagado');
            })
            ->get();

        if ($inactiveCredits->count() > 0) {
            $this->info("\nFound " . $inactiveCredits->count() . " credits with NO pending installments but status not 'Liquidado'");
            foreach ($inactiveCredits as $credit) {
                $this->warn("Credit ID: {$credit->id} | Balance: {$credit->remaining_amount} | Status: {$credit->status}");
                if (!$dryRun) {
                    try {
                        DB::beginTransaction();

                        // Zero out any remaining unapplied money
                        $unappliedPayments = \App\Models\Payment::where('credit_id', $credit->id)
                            ->where('unapplied_amount', '>', 0)
                            ->get();
                        
                        foreach ($unappliedPayments as $payment) {
                            $payment->unapplied_amount = 0;
                            $payment->save();
                        }

                        $credit->remaining_amount = 0;
                        $credit->status = 'Liquidado';
                        $credit->save();

                        DB::commit();
                        $this->info("Successfully set Credit #{$credit->id} to Liquidado and cleared unapplied");
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error("Failed to update Credit #{$credit->id}: " . $e->getMessage());
                    }
                }
            }
        }

        $this->info("\n--- TASK COMPLETED ---");
        return 0;
    }
}
