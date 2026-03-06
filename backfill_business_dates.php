<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\Expense;
use Carbon\Carbon;

echo "Iniciando limpieza de datos históricos...\n";

$incomes = Income::whereNull('business_date')->get();
echo "Corrigiendo " . $incomes->count() . " ingresos...\n";
foreach($incomes as $i) {
    // Asignar el componente fecha de created_at como business_date
    $i->business_date = Carbon::parse($i->created_at)->toDateString();
    $i->save();
}

$expenses = Expense::whereNull('business_date')->get();
echo "Corrigiendo " . $expenses->count() . " gastos...\n";
foreach($expenses as $e) {
    $e->business_date = Carbon::parse($e->created_at)->toDateString();
    $e->save();
}

echo "Limpieza completada. Ya no quedan registros sin business_date.\n";
