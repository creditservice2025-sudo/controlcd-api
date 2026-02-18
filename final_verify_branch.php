<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\Seller;
use App\Services\LiquidationService;

$sellerId = 52; // User 60
$ls = app(LiquidationService::class);

echo "Verificación de Totales para Vendedor $sellerId\n";

$metrics17 = $ls->calculateLiquidationMetrics($sellerId, '2026-02-17');
echo "2026-02-17 - Total Ingresos: " . $metrics17['total_income'] . "\n";

$metrics18 = $ls->calculateLiquidationMetrics($sellerId, '2026-02-18');
echo "2026-02-18 - Total Ingresos: " . $metrics18['total_income'] . "\n";

$income269 = Income::find(269);
echo "Ingreso 269 - business_date: " . ($income269->business_date->toDateString()) . " | value: " . $income269->value . "\n";
