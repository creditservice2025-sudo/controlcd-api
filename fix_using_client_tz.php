<?php

use App\Models\Expense;
use App\Models\Income;
use App\Services\LiquidationService;
use Carbon\Carbon;

$expenses = Expense::where('user_id', 23)
    ->whereDate('created_at', '>=', '2026-01-21') 
    ->get();

echo "Procesando " . $expenses->count() . " gastos...\n";

foreach ($expenses as $expense) {
    // Si tiene client_timezone, ESE es el que manda.
    if (!empty($expense->client_timezone)) {
        $targetTz = $expense->client_timezone;
        
        // Si el business_timezone es diferente, o la fecha no cuadra con ese timezone
        if ($expense->business_timezone !== $targetTz) {
            echo "Expense {$expense->id}: Switching form {$expense->business_timezone} to Client TZ {$targetTz}...\n";
            
            $expense->business_timezone = $targetTz;
            $expense->business_timestamp = Carbon::parse($expense->created_at)->setTimezone($targetTz);
            $expense->business_date = $expense->business_timestamp->toDateString();
            $expense->save();
            
            echo "New Date: {$expense->business_date}\n";
            
            // Recalculate Liquidation for the NEW date
            app(LiquidationService::class)->recalculateLiquidation(27, $expense->business_date); // Hardcoded seller ID 27 based on prev logs (User 23 -> Seller 27?) 
            // Wait, let's find seller dynamic
             $seller = \App\Models\Seller::where('user_id', $expense->user_id)->first();
             if ($seller) {
                 app(LiquidationService::class)->recalculateLiquidation($seller->id, $expense->business_date);
                 echo "Liquidation recalculated for Seller {$seller->id}.\n";
             }
        } else {
             // Even if TZ matches, maybe date is wrong if it was calculated with old logic (unlikely if TZ matches, but check)
             $properDate = Carbon::parse($expense->created_at)->setTimezone($targetTz)->toDateString();
             if ($expense->business_date !== $properDate) {
                 echo "Expense {$expense->id}: TZ OK but Date wrong. Fixing...\n";
                 $expense->business_date = $properDate;
                 $expense->save();
                  $seller = \App\Models\Seller::where('user_id', $expense->user_id)->first();
                 if ($seller) {
                     app(LiquidationService::class)->recalculateLiquidation($seller->id, $expense->business_date);
                 }
             }
        }
    }
}

echo "Done.\n";
