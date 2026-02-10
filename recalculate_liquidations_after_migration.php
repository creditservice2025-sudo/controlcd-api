<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Seller;
use App\Services\LiquidationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Ensure we are in the correct environment
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$liquidationService = app(LiquidationService::class);

// Look for records updated in the last 2 hours (covering the time the previous scripts ran)
$cutoffTime = Carbon::now()->subHours(2);

echo "Searching for expenses and incomes updated after: {$cutoffTime}\n";

// 1. Find affected User IDs and Earliest Dates
$affectedSellers = []; // [seller_id => min_date]

// Check Expenses
$updatedExpenses = Expense::where('updated_at', '>=', $cutoffTime)
    ->whereNotNull('business_date')
    ->get()
    ->groupBy('user_id');

foreach ($updatedExpenses as $userId => $expenses) {
    $minDate = $expenses->min('business_date');
    $seller = Seller::where('user_id', $userId)->first();

    if ($seller) {
        if (!isset($affectedSellers[$seller->id]) || $minDate < $affectedSellers[$seller->id]) {
            $affectedSellers[$seller->id] = $minDate;
        }
    }
}

echo "Found " . $updatedExpenses->count() . " users with updated expenses.\n";

// Check Incomes
$updatedIncomes = Income::where('updated_at', '>=', $cutoffTime)
    ->whereNotNull('business_date')
    ->get()
    ->groupBy('user_id');

foreach ($updatedIncomes as $userId => $incomes) {
    $minDate = $incomes->min('business_date');
    $seller = Seller::where('user_id', $userId)->first();

    if ($seller) {
        if (!isset($affectedSellers[$seller->id]) || $minDate < $affectedSellers[$seller->id]) {
            $affectedSellers[$seller->id] = $minDate;
        }
    }
}

echo "Found " . $updatedIncomes->count() . " users with updated incomes.\n";
echo "Total affected sellers to recalculate: " . count($affectedSellers) . "\n\n";

if (empty($affectedSellers)) {
    echo "No sellers require recalculation based on recent updates.\n";
    exit;
}

// 2. Perform Recalculation
foreach ($affectedSellers as $sellerId => $dateStr) {
    try {
        // Only valid dates
        if (!$dateStr)
            continue;

        // Convert to string just in case
        $date = ($dateStr instanceof Carbon) ? $dateStr->toDateString() : $dateStr;

        echo "Recalculating Seller {$sellerId} starting from {$date}...\n";

        // 1. Recalculate specific date
        $liquidationService->recalculateLiquidation($sellerId, $date);

        // 2. Recalculate all subsequent dates
        $liquidationService->recalculateNextLiquidations($sellerId, $date);

        echo "  [OK] Completed Seller {$sellerId}\n";

    } catch (\Exception $e) {
        echo "  [ERROR] Failed Seller {$sellerId}: " . $e->getMessage() . "\n";
    }
}

echo "\nRecalculation process finished.\n";
