<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Client;
use App\Models\Credit;

$user = User::where('name', 'like', '%Harold3%')->first();
if (!$user || !$user->seller) {
    echo "User or Seller not found\n";
    exit;
}

$seller = $user->seller;
echo "Seller: " . $user->name . " (ID: " . $seller->id . ")\n";

// New Logic
$creditsCount = $seller->credits()
    ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable'])
    ->count();

$clientsWithActiveCredits = Client::where('seller_id', $seller->id)
    ->whereHas('credits', function ($query) {
        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
    })->count();

$clientsNoCredits = Client::where('seller_id', $seller->id)
    ->whereDoesntHave('credits', function ($query) {
        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
    })->count();

$totalClients = $seller->clients()->count();

echo "\nNEW LOGIC RESULTS:\n";
echo "  Active Credits: " . $creditsCount . " (Expected: 135)\n";
echo "  Clients with Active Credits: " . $clientsWithActiveCredits . " (Expected: 133)\n";
echo "  Clients WITHOUT Active Credits: " . $clientsNoCredits . " (Expected: 31)\n";
echo "  Total Clients check (Active + Without): " . ($clientsWithActiveCredits + $clientsNoCredits) . " (Total DB: " . $totalClients . ")\n";

if (($clientsWithActiveCredits + $clientsNoCredits) == $totalClients) {
    echo "\nSUCCESS: The numbers are consistent with the total client count.\n";
} else {
    echo "\nWARNING: There is a discrepancy in the client totals.\n";
}
