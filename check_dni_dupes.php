<?php

use Illuminate\Support\Facades\DB;
use App\Models\Client;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "--- Checking for DNI duplicates in clients table ---\n";

$duplicates = DB::table('clients')
    ->select('dni', 'seller_id', DB::raw('count(*) as count'))
    ->groupBy('dni', 'seller_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicates->isEmpty()) {
    echo "No (DNI, seller_id) duplicates found.\n";
} else {
    echo "Found " . $duplicates->count() . " (DNI, seller_id) duplicates:\n";
    foreach ($duplicates as $dup) {
        echo "DNI: {$dup->dni}, Seller ID: {$dup->seller_id}, Count: {$dup->count}\n";
    }
}

echo "\n--- Checking for potential cross-seller duplicates ---\n";
$crossDuplicates = DB::table('clients')
    ->select('dni', DB::raw('count(DISTINCT seller_id) as seller_count'), DB::raw('count(*) as total_count'))
    ->groupBy('dni')
    ->having('total_count', '>', 1)
    ->get();

foreach ($crossDuplicates as $dup) {
    echo "DNI: {$dup->dni}, Unique Sellers: {$dup->seller_count}, Total Records: {$dup->total_count}\n";
}

echo "\n--- Checking for soft-deleted duplicates ---\n";
$softDeleted = DB::table('clients')
    ->whereNotNull('deleted_at')
    ->select('dni', 'seller_id', 'id')
    ->get();

foreach ($softDeleted as $sd) {
    // Check if there is an active one with same DNI/seller
    $active = DB::table('clients')
        ->whereNull('deleted_at')
        ->where('dni', $sd->dni)
        ->where('seller_id', $sd->seller_id)
        ->first();
    if ($active) {
        echo "DNI: {$sd->dni}, Seller: {$sd->seller_id} has both ACTIVE (ID: {$active->id}) and DELETED (ID: {$sd->id}) records.\n";
    }
}
