<?php

use App\Models\Liquidation;
use App\Services\LiquidationService;

// 1. Fix specific IDs from screenshot to be sure
$targetIds = [746, 763];
$service = app(LiquidationService::class);

echo "--- Fixing Targeted IDs ---\n";
foreach($targetIds as $id) {
    $l = Liquidation::find($id);
    if($l) {
        echo "Fixing ID $id (Date: {$l->date->format('Y-m-d')})...\n";
        $service->recalculateLiquidation($l->seller_id, $l->date->format('Y-m-d'));
        $l->refresh();
        echo " -> Result Poliza: {$l->poliza}\n";
    }
}

// 2. Broad search for others (handling NULLs)
echo "\n--- Scanning for others ---\n";
$others = Liquidation::where('new_credits', '>', 0)
    ->where(function($q) {
        $q->whereNull('poliza')->orWhere('poliza', 0);
    })
    ->get();

echo "Found " . $others->count() . " potential others.\n";
foreach($others as $l) {
    if (in_array($l->id, $targetIds)) continue; // Skip already done
    
    echo "Fixing ID {$l->id}...\n";
    $service->recalculateLiquidation($l->seller_id, $l->date->format('Y-m-d'));
}
