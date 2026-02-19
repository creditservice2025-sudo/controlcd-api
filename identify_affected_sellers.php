<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Expense;
use App\Models\User;

$nullExpenses = Expense::whereNull('created_at')
    ->select('user_id', \DB::raw('count(*) as total'))
    ->groupBy('user_id')
    ->get();

echo "Sellers/Users affected by NULL created_at in expenses:\n";
if($nullExpenses->isEmpty()) {
    echo "No affected users found.\n";
}

foreach($nullExpenses as $item) {
    if (!$item->user_id) {
        echo "User ID: NULL | Total Expenses: {$item->total}\n";
        continue;
    }
    $u = User::find($item->user_id);
    $name = $u ? $u->name : "Unknown";
    echo "User ID: {$item->user_id} | Name: {$name} | Total Expenses: {$item->total}\n";
    
    // List specific IDs for this user
    $ids = Expense::whereNull('created_at')->where('user_id', $item->user_id)->pluck('id')->toArray();
    echo "  Affected IDs: " . implode(', ', $ids) . "\n";
}
