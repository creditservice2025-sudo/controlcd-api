<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\Liquidation;

$sellerId = 52;
$userId = 60;

echo "--- LIQUIDACIONES VENDEDOR 52 (Filtro por Date) ---\n";
$liquidations = Liquidation::where('seller_id', $sellerId)->whereBetween('date', ['2026-02-17', '2026-02-19'])->get();
foreach($liquidations as $l) {
    echo "ID: {$l->id} | date: {$l->date} | total_income: {$l->total_income} | status: {$l->status}\n";
}

echo "\n--- TODOS LOS INGRESOS DEL USER 60 (Filtro por business_date) ---\n";
$incomes = Income::where('user_id', $userId)->whereBetween('business_date', ['2026-02-17', '2026-02-19'])->get();
foreach($incomes as $i) {
    echo "ID: {$i->id} | business_date: " . ($i->business_date->toDateString()) . " | value: {$i->value} | created_at: {$i->created_at}\n";
}

echo "\n--- COMPARACIÓN DE CÁLCULO VS PERSISTENCIA (18-Feb) ---\n";
$ls = app(\App\Services\LiquidationService::class);
$metrics = $ls->calculateLiquidationMetrics($sellerId, '2026-02-18');
echo "Metrics Calculated (total_income): " . ($metrics['total_income'] ?? 'N/A') . "\n";
