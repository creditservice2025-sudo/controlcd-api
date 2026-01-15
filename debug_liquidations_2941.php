<?php

use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\Credit;

// Get the seller from the credit shown in the image (Crédito #2941)
$credit = Credit::find(2941);
if (!$credit) {
    echo "Credit 2941 not found\n";
    exit;
}

$sellerId = $credit->seller_id;
echo "Credit #2941 belongs to Seller ID: {$sellerId}\n";
echo "Credit created at: {$credit->created_at}\n";
echo "Credit start date: {$credit->start_date}\n\n";

// Get all liquidations for this seller
$liquidations = Liquidation::where('seller_id', $sellerId)
    ->orderBy('date', 'DESC')
    ->get();

echo "Total liquidations for seller {$sellerId}: " . $liquidations->count() . "\n\n";

if ($liquidations->count() > 0) {
    echo "Liquidations (ordered by date DESC):\n";
    echo str_repeat('-', 80) . "\n";
    foreach ($liquidations as $liq) {
        echo sprintf(
            "ID: %5d | Date: %s | Status: %s | Real to Deliver: %s\n",
            $liq->id,
            $liq->date,
            $liq->status ?? 'N/A',
            number_format($liq->real_to_deliver ?? 0, 2)
        );
    }
    echo str_repeat('-', 80) . "\n";
    
    // Check if there are liquidations after 2026-01-08
    $recentLiquidations = Liquidation::where('seller_id', $sellerId)
        ->where('date', '>', '2026-01-08')
        ->orderBy('date', 'DESC')
        ->get();
    
    echo "\nLiquidations AFTER 2026-01-08: " . $recentLiquidations->count() . "\n";
    if ($recentLiquidations->count() > 0) {
        foreach ($recentLiquidations as $liq) {
            echo "  - Date: {$liq->date}, ID: {$liq->id}\n";
        }
    }
} else {
    echo "No liquidations found for this seller.\n";
}
