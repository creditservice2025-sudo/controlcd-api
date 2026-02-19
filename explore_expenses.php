<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;

echo "First 50 expenses:\n";
$expenses = Expense::orderBy('id', 'asc')->limit(50)->get();

foreach($expenses as $e) {
    echo "ID: {$e->id} | User ID: {$e->user_id} | Value: {$e->value} | business_date: ".($e->business_date ? $e->business_date->toDateString() : 'NULL')." | created_at: " . ($e->created_at ?: 'NULL') . " | description: {$e->description}\n";
}
