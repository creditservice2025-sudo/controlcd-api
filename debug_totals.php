<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "--- EXPENSES ANALYSIS ---\n";
$totalExpensesDB = Expense::whereNull('deleted_at')->sum('value');
echo "1. Total Expenses (deleted_at IS NULL): " . number_format($totalExpensesDB, 2) . "\n";

$expensesWithSeller = Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->whereNull('expenses.deleted_at')
    ->sum('expenses.value');
echo "2. Expenses WITH Seller join: " . number_format($expensesWithSeller, 2) . "\n";

$expensesWithoutSeller = DB::table('expenses')
    ->leftJoin('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->whereNull('expenses.deleted_at')
    ->whereNull('sellers.id')
    ->sum('expenses.value');
echo "3. Expenses WITHOUT Seller mapping: " . number_format($expensesWithoutSeller, 2) . "\n";

// Date filter used in dashboard
$start = Carbon::create(2000, 1, 1, 0, 0, 0, 'UTC');
$end = Carbon::now('America/Bogota')->addYears(10)->timezone('UTC');
$startDateString = $start->toDateString();
$endDateString = $end->toDateString();

$expensesWithDateFilter = Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->whereNull('expenses.deleted_at')
    ->where(function($q) use ($startDateString, $endDateString, $start, $end) {
        $q->whereBetween('expenses.business_date', [$startDateString, $endDateString])
          ->orWhereBetween('expenses.created_at', [$start, $end]);
    })
    ->sum('expenses.value');
echo "4. Expenses WITH Seller + Date Filter: " . number_format($expensesWithDateFilter, 2) . "\n";

echo "\n--- PAYMENTS ANALYSIS ---\n";
$totalPaymentsDB = Payment::whereNull('deleted_at')->sum('amount');
echo "1. Total Payments (deleted_at IS NULL): " . number_format($totalPaymentsDB, 2) . "\n";

$paymentsWithSeller = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
    ->join('sellers', 'credits.seller_id', '=', 'sellers.id')
    ->whereNull('payments.deleted_at')
    ->sum('payments.amount');
echo "2. Payments WITH Credit/Seller join: " . number_format($paymentsWithSeller, 2) . "\n";

$paymentsWithoutCredit = DB::table('payments')
    ->leftJoin('credits', 'payments.credit_id', '=', 'credits.id')
    ->whereNull('payments.deleted_at')
    ->whereNull('credits.id')
    ->sum('payments.amount');
echo "3. Payments WITHOUT Credit mapping: " . number_format($paymentsWithoutCredit, 2) . "\n";

$paymentsWithDateFilter = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
    ->whereNull('payments.deleted_at')
    ->where(function($q) use ($startDateString, $endDateString, $start, $end) {
        $q->whereBetween('payments.business_date', [$startDateString, $endDateString])
          ->orWhereBetween('payments.created_at', [$start, $end]);
    })
    ->sum('payments.amount');
echo "4. Payments WITH Credit + Date Filter: " . number_format($paymentsWithDateFilter, 2) . "\n";
