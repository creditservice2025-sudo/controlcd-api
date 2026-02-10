<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Seller;
use App\Services\LiquidationService;
use Carbon\Carbon;

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get arguments
if (count($argv) < 2) {
    die("Usage: php recalculate_seller_liquidations.php <seller_id> [start_date]\n");
}

$sellerId = $argv[1];
$startDate = $argv[2] ?? null;

$seller = Seller::find($sellerId);
if (!$seller) {
    die("Seller ID {$sellerId} not found.\n");
}

$liquidationService = app(LiquidationService::class);
$userId = $seller->user_id;

echo "Targeting Seller: {$seller->id} (User ID: {$userId})\n";

if (!$startDate) {
    // Auto-detect start date based on recent updates
    echo "No start date provided. Searching for recent updates (last 24 hours)...\n";
    $cutoffTime = Carbon::now()->subHours(24);

    $minExpenseDate = Expense::where('user_id', $userId)
        ->where('updated_at', '>=', $cutoffTime)
        ->whereNotNull('business_date')
        ->min('business_date');

    $minIncomeDate = Income::where('user_id', $userId)
        ->where('updated_at', '>=', $cutoffTime)
        ->whereNotNull('business_date')
        ->min('business_date');

    $dates = array_filter([$minExpenseDate, $minIncomeDate]);

    if (empty($dates)) {
        die("No recent updates found for this seller. Please provide a start date manually.\n");
    }

    $startDate = min($dates);
}

echo "Starting recalculation from: {$startDate}\n";

try {
    // 1. Recalculate specific date
    $liquidationService->recalculateLiquidation($sellerId, $startDate);

    // 2. Recalculate all subsequent dates
    $liquidationService->recalculateNextLiquidations($sellerId, $startDate);

    echo "Successfully recalculated liquidations for Seller {$sellerId}.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
