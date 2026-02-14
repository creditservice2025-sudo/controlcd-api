<?php

use App\Models\User;
use App\Models\Country;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Debugging Phone Code ---\n";

// 1. Check Country Data
$countries = Country::whereNotNull('phone_code')->get();
echo "Countries with phone_code: " . $countries->count() . "\n";
foreach ($countries as $c) {
    echo "  - {$c->name}: {$c->phone_code}\n";
}

// 2. Check Specific User (Alejandra3)
$ale = User::where('email', 'alejandra3@example.com')->first();
if ($ale) {
    echo "\nUser: Alejandra3 ({$ale->email})\n";
    echo "  - Seller: " . ($ale->seller ? 'Yes (ID: ' . $ale->seller->id . ')' : 'No') . "\n";
    if ($ale->seller) {
        echo "  - City: " . ($ale->seller->city ? $ale->seller->city->name : 'None') . "\n";
        if ($ale->seller->city) {
            echo "  - Country: " . ($ale->seller->city->country ? $ale->seller->city->country->name : 'None') . "\n";
            echo "  - Phone Code from Relation: " . ($ale->seller->city->country ? $ale->seller->city->country->phone_code : 'N/A') . "\n";
        }
    }
} else {
    echo "\nUser Alejandra3 not found.\n";
}

// 4. List Active Sellers
echo "\n--- Active Sellers ---\n";
$sellers = User::has('seller')->with('seller.city.country')->take(10)->get();
foreach ($sellers as $u) {
    $countryName = $u->seller->city?->country?->name ?? 'None';
    $phoneCode = $u->seller->city?->country?->phone_code ?? 'None';
    echo "User: {$u->email} | Country: {$countryName} | Code: {$phoneCode}\n";
}
