<?php
// fix_remaining_2.php

use App\Services\LiquidationService;

$service = app(LiquidationService::class);

$ids = [366, 376];
$fixed = 0;

foreach ($ids as $id) {
    try {
        $liq = \App\Models\Liquidation::find($id);
        if ($liq) {
            echo "Recalculating Liquidation ID: {$id}...\n";
            $service->recalculateLiquidation($liq->seller_id, $liq->date->toDateString());
            $fixed++;
        }
    } catch (\Exception $e) {
        echo "Error fixing {$id}: " . $e->getMessage() . "\n";
    }
}

echo "Attempts completed. Fixed: " . $fixed . "\n";
exit();
