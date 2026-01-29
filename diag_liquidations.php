<?php
use App\Models\Seller;
use App\Models\Liquidation;

$seller = Seller::whereHas('user', function($query) {
    $query->where('name', 'like', '%Alejandra3%');
})->first();

if (!$seller) {
    echo "Alejandra3 not found\n";
    exit;
}

echo "Seller ID: " . $seller->id . "\n";
echo "User ID: " . $seller->user_id . "\n";
echo "User Name: " . $seller->user->name . "\n\n";

echo "Seller ID: " . $seller->id . "\n";
echo "Seller Name: " . $seller->user->name . "\n\n";

$liquidations = Liquidation::where('seller_id', $seller->id)
    ->withTrashed()
    ->orderBy('date', 'desc')
    ->take(20)
    ->get();

echo "Date | Status | ID | Created At | Deleted At | End Date\n";
echo "------------------------------------------------------------\n";
foreach ($liquidations as $l) {
    echo $l->date . " | " . $l->status . " | " . $l->id . " | " . $l->created_at . " | " . ($l->deleted_at ?? 'N/A') . " | " . ($l->end_date ?? 'N/A') . "\n";
}

// Buscar específicamente por la fecha 27/01
echo "\nChecking duplicates for 2026-01-27:\n";
$dupes27 = Liquidation::where('seller_id', $seller->id)
    ->whereDate('date', '2026-01-27')
    ->get();
foreach ($dupes27 as $d) {
    echo "ID: " . $d->id . " | Status: '" . $d->status . "' | Created: " . $d->created_at . "\n";
}
