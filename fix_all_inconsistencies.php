<?php

use App\Models\Credit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Starting Credit Inconsistency Fixer...\n";
Log::info("Starting Credit Inconsistency Fixer");

// 1. Process ACTIVE credits that might be fully paid
$query = Credit::whereNotIn('status', ['Liquidado', 'Anulado', 'Rechazado']);
$count = $query->count();
echo "Scanning {$count} active credits for 'Paid but Active' inconsistency...\n";

$fixedCount = 0;
$query->chunk(100, function ($credits) use (&$fixedCount) {
    foreach ($credits as $credit) {
        try {
            $calculatedPending = $credit->pendingAmount();
            
            // Case A: Calculated balance is 0 (Paid), but status is NOT Liquidado
            if ($calculatedPending < 0.01) {
                echo "Fixing Credit #{$credit->id} (Status: {$credit->status}, DB Remaining: {$credit->remaining_amount}) -> Liquidado\n";
                Log::info("Fixing Credit #{$credit->id}: Setting status to Liquidado. Old Status: {$credit->status}, Old Remaining: {$credit->remaining_amount}");
                
                $credit->status = 'Liquidado';
                $credit->remaining_amount = 0;
                $credit->save();
                $fixedCount++;
                continue;
            }

            // Case B: Calculated balance differs from DB column
            if (abs($credit->remaining_amount - $calculatedPending) > 0.05) {
                echo "Fixing Credit #{$credit->id} Balance Mismatch (DB: {$credit->remaining_amount} -> Calc: {$calculatedPending})\n";
                Log::info("Fixing Credit #{$credit->id}: Updating remaining_amount. Old: {$credit->remaining_amount}, New: {$calculatedPending}");
                
                $credit->remaining_amount = $calculatedPending;
                $credit->save();
                $fixedCount++;
            }

        } catch (\Exception $e) {
            echo "Error processing Credit {$credit->id}: " . $e->getMessage() . "\n";
            Log::error("Error processing Credit {$credit->id}: " . $e->getMessage());
        }
    }
});

echo "\nDone. Fixed {$fixedCount} credits.\n";
echo "You can check the logs in storage/logs/laravel.log for details.\n";
