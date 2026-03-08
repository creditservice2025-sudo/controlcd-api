<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Expense;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;

$totalSum = Expense::whereNull('deleted_at')->sum('value');
$activeSellerUserIds = Seller::pluck('user_id')->all();
$sumFromActiveSellers = Expense::whereIn('user_id', $activeSellerUserIds)->whereNull('deleted_at')->sum('value');

echo "Total expenses (deleted_at IS NULL): " . number_format($totalSum, 2) . "\n";
echo "Expenses from ACTIVE sellers: " . number_format($sumFromActiveSellers, 2) . "\n";
echo "Difference: " . number_format($totalSum - $sumFromActiveSellers, 2) . "\n";

if ($totalSum != $sumFromActiveSellers) {
    echo "\nSample of expenses from users NOT in sellers table:\n";
    $missing = DB::table('expenses')
        ->leftJoin('sellers', 'expenses.user_id', '=', 'sellers.user_id')
        ->whereNull('expenses.deleted_at')
        ->whereNull('sellers.id')
        ->select('expenses.user_id', DB::raw('sum(expenses.value) as total'))
        ->groupBy('expenses.user_id')
        ->get();
    foreach ($missing as $m) {
        $user = DB::table('users')->where('id', $m->user_id)->first();
        echo "User ID: {$m->user_id} (" . ($user ? $user->name : 'N/A') . ") - Sum: " . number_format($m->total, 2) . "\n";
    }
}
