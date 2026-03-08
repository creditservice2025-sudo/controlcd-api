<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$dupes = DB::table('sellers')
    ->select('user_id', DB::raw('count(*) as count'))
    ->groupBy('user_id')
    ->having('count', '>', 1)
    ->get();

echo "Duplicate user_ids in sellers: " . $dupes->count() . "\n";
foreach($dupes as $d) {
    $user = DB::table('users')->where('id', $d->user_id)->first();
    echo "User ID: {$d->user_id} (" . ($user ? $user->name : 'N/A') . ") - Count: {$d->count}\n";
    
    // Check companies for these dupes
    $companies = DB::table('sellers')->where('user_id', $d->user_id)->pluck('company_id')->toArray();
    echo "  Companies: " . implode(', ', $companies) . "\n";
}
