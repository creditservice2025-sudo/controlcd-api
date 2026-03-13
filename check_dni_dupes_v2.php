<?php

use Illuminate\Support\Facades\DB;
use App\Models\Client;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "--- Checking for DNI duplicates WITHIN THE SAME SELLER ---\n";

$dupes = DB::table('clients')
    ->select('dni', 'seller_id', DB::raw('count(*) as count'))
    ->groupBy('dni', 'seller_id')
    ->having('count', '>', 1)
    ->get();

if ($dupes->isEmpty()) {
    echo "No absolute (DNI, seller_id) duplicates found (including soft deleted).\n";
} else {
    foreach ($dupes as $d) {
        echo "DUPLICATE: DNI {$d->dni} in seller {$d->seller_id} appears {$d->count} times.\n";
        $ids = DB::table('clients')->where('dni', $d->dni)->where('seller_id', $d->seller_id)->pluck('id', 'deleted_at');
        print_r($ids);
    }
}

echo "\n--- Checking for SOFT-DELETED vs ACTIVE duplicates ---\n";
// Sometimes groups can hide them if we only count.
$dniSellers = DB::table('clients')->select('dni', 'seller_id')->distinct()->get();
foreach ($dniSellers as $ds) {
    $active = DB::table('clients')->where('dni', $ds->dni)->where('seller_id', $ds->seller_id)->whereNull('deleted_at')->count();
    $deleted = DB::table('clients')->where('dni', $ds->dni)->where('seller_id', $ds->seller_id)->whereNotNull('deleted_at')->count();
    
    if ($active > 0 && $deleted > 0) {
        echo "Conflict: DNI {$ds->dni} in Seller {$ds->seller_id} has {$active} active and {$deleted} deleted records.\n";
    }
}
