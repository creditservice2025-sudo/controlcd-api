<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\User;

$nullIncomes = Income::whereNull('created_at')
    ->select('user_id', \DB::raw('count(*) as total'))
    ->groupBy('user_id')
    ->get();

echo "Sellers/Users affected by NULL created_at in incomes:\n";
if($nullIncomes->isEmpty()) {
    echo "No affected users found in incomes.\n";
}

foreach($nullIncomes as $item) {
    if (!$item->user_id) {
        echo "User ID: NULL | Total Incomes: {$item->total}\n";
        continue;
    }
    $u = User::find($item->user_id);
    $name = $u ? $u->name : "Unknown";
    echo "User ID: {$item->user_id} | Name: {$name} | Total Incomes: {$item->total}\n";
    
    $ids = Income::whereNull('created_at')->where('user_id', $item->user_id)->pluck('id')->toArray();
    echo "  Affected IDs: " . implode(', ', $ids) . "\n";
}
