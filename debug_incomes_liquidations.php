<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\Liquidation;

$sellerId = 52;
$userId = 60;

echo "--- INGRESOS VENDEDOR 52 (User 60) ---\n";
$incomes = Income::where('user_id', $userId)->whereBetween('business_date', ['2026-02-15', '2026-02-20'])->get();
foreach($incomes as $i) {
    echo "ID: {$i->id} | business_date: " . ($i->business_date->toDateString()) . " | value: {$i->value} | created_at: {$i->created_at}\n";
}

echo "\n--- LIQUIDACIONES VENDEDOR 52 ---\n";
$liquidations = Liquidation::where('seller_id', $sellerId)->whereBetween('date', ['2026-02-15', '2026-02-20'])->get();
foreach($liquidations as $l) {
    echo "ID: {$l->id} | date: {$l->date} | total_income: {$l->total_income} | status: {$l->status} | real_to_deliver: {$l->real_to_deliver}\n";
}
