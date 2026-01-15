<?php

use App\Models\Liquidation;
use App\Services\LiquidationService;
use Illuminate\Support\Facades\Log;

// Find liquidations that look suspicious: explicit new credits but 0 policy
$liquidations = Liquidation::where('new_credits', '>', 0)
    ->where('poliza', 0)
    ->get();

echo "Found " . $liquidations->count() . " liquidations to fix.\n";

$service = app(LiquidationService::class);

foreach ($liquidations as $l) {
    echo "Fixing Liquidation ID: {$l->id} (Seller: {$l->seller_id}, Date: {$l->date->format('Y-m-d')})...\n";
    try {
        $service->recalculateLiquidation($l->seller_id, $l->date->format('Y-m-d'));
        $l->refresh();
        echo " -> Fixed. New Poliza: {$l->poliza}\n";
    } catch (\Exception $e) {
        echo " -> Error: " . $e->getMessage() . "\n";
    }
}
