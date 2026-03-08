<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$res = DB::table('expenses')
    ->join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->select('sellers.company_id', DB::raw('sum(expenses.value) as total'), DB::raw('count(*) as count'))
    ->groupBy('sellers.company_id')
    ->get();

echo "Expenses by Company:\n";
foreach($res as $r) {
    echo "Company ID: {$r->company_id} - Total: " . number_format($r->total, 2) . " (Count: {$r->count})\n";
}

$resPay = DB::table('payments')
    ->join('credits', 'payments.credit_id', '=', 'credits.id')
    ->join('sellers', 'credits.seller_id', '=', 'sellers.id')
    ->select('sellers.company_id', DB::raw('sum(payments.amount) as total'), DB::raw('count(*) as count'))
    ->groupBy('sellers.company_id')
    ->get();

echo "\nPayments by Company:\n";
foreach($resPay as $r) {
    echo "Company ID: {$r->company_id} - Total: " . number_format($r->total, 2) . " (Count: {$r->count})\n";
}
