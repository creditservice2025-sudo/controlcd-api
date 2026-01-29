<?php
use App\Models\Liquidation;
use App\Models\Seller;
use Carbon\Carbon;

$sellerId = 18; // Alejandra3
$dateConsulted = '2026-01-29';
$timezone = 'America/Lima';

$seller = Seller::with('user')->find($sellerId);
echo "Diagnosing Seller: " . ($seller->user->name ?? 'Unknown') . " (ID: $sellerId)\n";

$inputDateLocal = Carbon::parse($dateConsulted, $timezone)->startOfDay();
echo "Consulted Date: " . $inputDateLocal->toDateTimeString() . "\n\n";

echo "All Liquidations for Seller $sellerId between 2026-01-25 and 2026-01-29:\n";
$liquidations = Liquidation::withTrashed()
    ->where('seller_id', $sellerId)
    ->whereBetween('date', ['2026-01-25', '2026-01-29'])
    ->orderBy('date', 'asc')
    ->get();

foreach ($liquidations as $liq) {
    echo "ID: {$liq->id} | Date: {$liq->date} | Status: [{$liq->status}] | Deleted: " . ($liq->deleted_at ?: 'No') . " | End Date: " . ($liq->end_date ?: 'NULL') . "\n";
}

echo "\n--- Running Controller Simulation ---\n";

$previousLiquidation = Liquidation::where('seller_id', $sellerId)
    ->whereDate('date', '<', $inputDateLocal)
    ->where('status', '!=', 'approved')
    ->orderBy('date', 'asc')
    ->first();

if ($previousLiquidation) {
    echo "Found Pending Liquidation: ID: {$previousLiquidation->id} | Date: {$previousLiquidation->date} | Status: {$previousLiquidation->status}\n";
    
    $lastApproved = Liquidation::where('seller_id', $sellerId)
        ->where('status', 'approved')
        ->where('date', '<', $previousLiquidation->date)
        ->orderByDesc('date')
        ->first();
    echo "Last Approved: " . ($lastApproved ? $lastApproved->date : 'None') . "\n";
} else {
    echo "No pending liquidations found before $dateConsulted.\n";
}
