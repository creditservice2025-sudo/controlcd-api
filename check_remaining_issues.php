<?php
// check_remaining_issues.php

use App\Models\Liquidation;
use Carbon\Carbon;

$issues = Liquidation::where('new_credits', '>', 0)
    ->where(function($q) {
        $q->whereNull('poliza')
          ->orWhere('poliza', 0);
    })
    ->get();

if ($issues->isEmpty()) {
    echo "NO remaining issues found. All historical data is correct.\n";
} else {
    echo "Found " . $issues->count() . " pending issues:\n";
    foreach ($issues as $liq) {
        echo "ID: {$liq->id} | Date: {$liq->date} | Seller: {$liq->seller_id} | New Credits: {$liq->new_credits} | Poliza: {$liq->poliza}\n";
    }
}
exit();
