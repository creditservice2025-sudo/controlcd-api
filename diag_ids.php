<?php
use App\Models\Seller;
use App\Models\Liquidation;

echo "--- Alejandra3 ---\n";
$s18 = Seller::whereHas('user', function($q) { $q->where('name', 'like', '%Alejandra3%'); })->first();
if ($s18) {
    echo "Seller ID: " . $s18->id . "\n";
    echo "User ID: " . $s18->user_id . "\n";
    echo "Name: " . $s18->user->name . "\n";
}

echo "\n--- Seller ID 25 ---\n";
$s25 = Seller::find(25);
if ($s25) {
    echo "Seller ID: " . $s25->id . "\n";
    echo "User ID: " . $s25->user_id . "\n";
    echo "Name: " . $s25->user->name . "\n";
}
