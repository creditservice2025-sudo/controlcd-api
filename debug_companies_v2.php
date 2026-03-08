<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Use DB::table to see everything including soft deleted sellers
$res = DB::table('expenses')
    ->join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->select('sellers.company_id', 'sellers.deleted_at as seller_deleted', DB::raw('sum(expenses.value) as total'), DB::raw('count(*) as count'))
    ->whereNull('expenses.deleted_at')
    ->groupBy('sellers.company_id', 'sellers.deleted_at')
    ->get();

echo "Expenses by Company and Seller Status:\n";
foreach($res as $r) {
    $status = $r->seller_deleted ? "DELETED ({$r->seller_deleted})" : "ACTIVE";
    echo "Company ID: " . ($r->company_id ?? 'NULL') . " - Status: {$status} - Total: " . number_format($r->total, 2) . " (Count: {$r->count})\n";
}

$resPay = DB::table('payments')
    ->join('credits', 'payments.credit_id', '=', 'credits.id')
    ->join('sellers', 'credits.seller_id', '=', 'sellers.id')
    ->select('sellers.company_id', 'sellers.deleted_at as seller_deleted', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as count'))
    ->whereNull('payments.deleted_at')
    ->groupBy('sellers.company_id', 'sellers.deleted_at')
    ->get();

echo "\nPayments by Company and Seller Status:\n";
foreach($resPay as $r) {
    $status = $r->seller_deleted ? "DELETED ({$r->seller_deleted})" : "ACTIVE";
    echo "Company ID: " . ($r->company_id ?? 'NULL') . " - Status: {$status} - Total: " . number_format($r->total, 2) . " (Count: {$r->count})\n";
}
