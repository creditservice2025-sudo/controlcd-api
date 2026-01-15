<?php
// Liquidation IDs reported as failing: 746 (6/1), 763 (7/1)
$ids = [746, 763];

foreach ($ids as $id) {
    echo "--------------------------------------------------\n";
    echo "Analyzing Liquidation ID: $id\n";
    $l = \App\Models\Liquidation::find($id);
    if (!$l) {
        echo "Liquidation not found.\n";
        continue;
    }
    echo "Date: " . $l->date->format('Y-m-d') . " | Stored Poliza: " . $l->poliza . " | New Credits: " . $l->new_credits . "\n";
    
    // Find associated credits
    // Logic from LiquidationController: where between start/end of day
    $date = $l->date;
    $startUTC = $date->copy()->startOfDay()->timezone('UTC');
    $endUTC = $date->copy()->endOfDay()->timezone('UTC');
    
    echo "Query Range UTC: " . $startUTC . " to " . $endUTC . "\n";
    
    $credits = \App\Models\Credit::where('seller_id', $l->seller_id)
        ->whereBetween('created_at', [$startUTC, $endUTC])
        ->get();
        
    echo "Found " . $credits->count() . " credits.\n";
    
    $calculatedPoliza = 0;
    foreach ($credits as $c) {
        $micro = $c->micro_insurance_percentage ?? 0;
        $val = $c->credit_value;
        $poliza = ($micro * $val / 100);
        $calculatedPoliza += $poliza;
        
        echo " - Credit ID: {$c->id} | Val: {$val} | Micro %: {$micro} | Calc Poliza: {$poliza}\n";
    }
    
    echo "Total Calculated Poliza SHOULD BE: " . $calculatedPoliza . "\n";
}
exit();
