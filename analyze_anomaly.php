<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;

$ids = [31, 32];
echo "Details for Expenses 31 and 32:\n";
$expenses = Expense::withTrashed()->whereIn('id', $ids)->get();

foreach($expenses as $e) {
    echo "ID: {$e->id}\n";
    echo "  User ID: {$e->user_id}\n";
    echo "  Value: {$e->value}\n";
    echo "  Business Date: ".($e->business_date ? $e->business_date->toDateString() : 'NULL')."\n";
    echo "  Created At: " . ($e->created_at ?: 'NULL') . "\n";
    echo "  Updated At: " . ($e->updated_at ?: 'NULL') . "\n";
    echo "  Deleted At: " . ($e->deleted_at ?: 'NULL') . "\n";
    echo "  Description: {$e->description}\n";
    echo "  Status: {$e->status}\n";
    echo "--------------------------\n";
}
