<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Expense;
use App\Models\Income;

$u = User::where('name', 'like', '%Alejandra3%')->first();
if(!$u) {
    echo "User Alejandra3 not found\n";
    exit;
}

$date = '2026-02-18';
echo "User ID: " . $u->id . " | Name: " . $u->name . "\n";
echo "Date: " . $date . "\n\n";

echo "--- EXPENSES ---\n";
$expenses = Expense::where('user_id', $u->id)
    ->where('business_date', $date)
    ->get();

foreach($expenses as $e) {
    echo "ID: {$e->id} | Value: {$e->value} | Created At: " . ($e->created_at ?: 'NULL') . " | Description: {$e->description}\n";
}

echo "\n--- INCOMES ---\n";
$incomes = Income::where('user_id', $u->id)
    ->where('business_date', $date)
    ->get();

foreach($incomes as $i) {
    echo "ID: {$i->id} | Value: {$i->value} | Created At: " . ($i->created_at ?: 'NULL') . " | Description: {$i->description}\n";
}
