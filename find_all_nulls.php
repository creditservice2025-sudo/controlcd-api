<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;

$nullExpenses = Expense::whereNull('created_at')->withTrashed()->get();

echo "Total expenses with NULL created_at: " . $nullExpenses->count() . "\n";
foreach($nullExpenses as $e) {
    echo "ID: {$e->id} | User: {$e->user_id} | Date: " . ($e->business_date ?: 'N/A') . " | Updated: {$e->updated_at} | Desc: {$e->description}\n";
}
