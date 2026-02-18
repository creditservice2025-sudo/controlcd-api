<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;

$userId = 60;

echo "--- TODOS LOS INGRESOS DEL USER 60 ---\n";
$incomes = Income::where('user_id', $userId)->get();
foreach($incomes as $i) {
    echo "ID: {$i->id} | business_date: " . ($i->business_date ? $i->business_date->toDateString() : 'NULL') . " | value: {$i->value} | created_at: {$i->created_at} | business_timestamp: {$i->business_timestamp}\n";
}
