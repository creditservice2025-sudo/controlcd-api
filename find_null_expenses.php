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

echo "Searching for expenses for User ID: " . $u->id . " with NULL created_at\n";
$expenses = Expense::where('user_id', $u->id)
    ->whereNull('created_at')
    ->get();

foreach($expenses as $e) {
    echo "ID: {$e->id} | Value: {$e->value} | business_date: ".($e->business_date ? $e->business_date->toDateString() : 'NULL')." | description: {$e->description}\n";
}

if($expenses->isEmpty()) {
    echo "No expenses found with NULL created_at for this user.\n";
}
