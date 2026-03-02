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

                    // Update all pending/partial installments
                    $installments = Installment::where('credit_id', $credit->id)
                        ->where('status', '!=', 'Pagado')
                        ->get();

                    foreach ($installments as $installment) {
                        $installment->paid_amount = $installment->quota_amount;
                        $installment->status = 'Pagado';
                        $installment->save();
                    }

                    // Liquidate Credit
                    $credit->remaining_amount = 0;
                    $credit->status = 'Liquidado';
                    $credit->save();

                    DB::commit();
                    $this->info("Successfully liquidated Credit #{$credit->id}");
                    Log::info("Precision Fix: Liquidated Credit #{$credit->id} with original balance {$credit->remaining_amount}");
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
                    $credit->remaining_amount = 0;
                    $credit->status = 'Liquidado';
                    $credit->save();
                    $this->info("Successfully set Credit #{$credit->id} to Liquidado");
                }
            }
        }

        $this->info("\n--- TASK COMPLETED ---");
        return 0;
    }
}
