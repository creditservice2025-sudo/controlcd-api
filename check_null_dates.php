<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\Expense;

echo "Incomes without business_date: " . Income::whereNull('business_date')->count() . "\n";
echo "Expenses without business_date: " . Expense::whereNull('business_date')->count() . "\n";
