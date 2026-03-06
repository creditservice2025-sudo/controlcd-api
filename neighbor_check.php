<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;

echo "Neighbors of ID 31 and 32:\n";
$neighbors = Expense::whereBetween('id', [25, 40])->withTrashed()->get();

foreach($neighbors as $e) {
    echo "ID: {$e->id} | User: {$e->user_id} | Created: " . ($e->created_at ?: 'NULL') . " | Updated: {$e->updated_at} | Desc: {$e->description}\n";
}
