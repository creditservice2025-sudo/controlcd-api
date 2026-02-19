<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Expense;

$u = User::where('name', 'like', '%Alejandra3%')->first();
if(!$u) {
    echo "User Alejandra3 not found\n";
    exit;
}

echo "User ID: " . $u->id . " | Name: " . $u->name . "\n";
$sellerId = $u->seller ? $u->seller->id : 'N/A';
echo "Seller ID: " . $sellerId . "\n";

echo "\nRecent Expenses for User " . $u->id . ":\n";
$expenses = Expense::where('user_id', $u->id)
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();

foreach($expenses as $e) {
    echo "ID: {$e->id} | Value: {$e->value} | business_date: ".($e->business_date ? $e->business_date->toDateString() : 'NULL')." | created_at: " . ($e->created_at ?: 'NULL') . " | description: {$e->description}\n";
}
