<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Liquidation;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get arguments
if (count($argv) < 2) {
    die("Usage: php verify_liquidation_calculations.php <seller_id> [start_date]\n");
}

$sellerId = $argv[1];
$startDate = $argv[2] ?? '2026-01-01';

$seller = Seller::find($sellerId);
if (!$seller) {
    die("Seller ID {$sellerId} not found.\n");
}

$userId = $seller->user_id;

echo "Verifying Liquidations for Seller: {$seller->id} (User ID: {$userId}) starting from {$startDate}\n";
echo "Date       \t| Exp(Liq) | Exp(DB) | Inc(Liq) | Inc(DB) | Diff? \n";
echo "--------------------------------------------------------\n";

$liquidations = Liquidation::where('seller_id', $sellerId)
    ->where('date', '>=', $startDate)
    ->orderBy('date', 'asc')
    ->get();

foreach ($liquidations as $liq) {
    $date = $liq->date->toDateString(); // Ensure Y-m-d

    // Calculate Expenses Manually
    $calculatedExpenses = Expense::where('user_id', $userId)
        ->where('business_date', $date)
        ->whereNull('deleted_at')
        ->where(function ($q) {
            $q->where('status', 'Aprobado')
                ->orWhere('description', 'like', '%AJUSTE%');
        })
        ->sum('value');

    // Calculate Incomes Manually
    $calculatedIncome = Income::where('user_id', $userId)
        ->where('business_date', $date)
        ->whereNull('deleted_at')
        ->sum('value');

    $expDiff = abs($liq->total_expenses - $calculatedExpenses) > 0.01;
    $incDiff = abs($liq->total_income - $calculatedIncome) > 0.01;

    $status = ($expDiff || $incDiff) ? "FAIL" : "OK";

    printf(
        "%s\t| %.2f\t| %.2f\t| %.2f\t| %.2f\t| %s\n",
        $date,
        $liq->total_expenses,
        $calculatedExpenses,
        $liq->total_income,
        $calculatedIncome,
        $status
    );

    if ($status === "FAIL") {
        echo " * Reason: Expenses or Income mismatch.\n";
    }
}

echo "--------------------------------------------------------\n";
echo "Verification complete.\n";
