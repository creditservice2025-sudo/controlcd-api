<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Expense;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

// Assuming company 4 (Super Admin likely belongs to it)
$companyId = 4;
$sellerIds = Seller::where('company_id', $companyId)->pluck('id')->all();

$filter = 'all';
$timezone = 'America/Bogota';
$start = Carbon::create(2000, 1, 1, 0, 0, 0, 'UTC');
$end = Carbon::now($timezone)->addYears(10)->timezone('UTC');
$startDateString = $start->toDateString();
$endDateString = $end->toDateString();

// SIMULATE DASHBOARD QUERY
$expensesDashboard = (float) Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->whereIn('sellers.id', $sellerIds)
    ->where(function($q) use ($startDateString, $endDateString, $start, $end) {
        $q->whereBetween('expenses.business_date', [$startDateString, $endDateString])
          ->orWhereBetween('expenses.created_at', [$start, $end]);
    })
    ->sum('expenses.value');

// SIMULATE USER DIRECT QUERY (But potentially restricted by company if we could)
$expensesDirectTotal = (float) Expense::whereNull('deleted_at')->sum('value');

echo "1. Dashboard Calculated Sum (Co 4): " . number_format($expensesDashboard, 2) . "\n";
echo "2. Direct DB Sum (Global): " . number_format($expensesDirectTotal, 2) . "\n";
echo "Difference: " . number_format($expensesDirectTotal - $expensesDashboard, 2) . "\n";

// Check if there are expenses for sellers of other companies
$otherSellers = Seller::where('company_id', '!=', $companyId)->pluck('id')->all();
if (!empty($otherSellers)) {
    $expensesOther = (float) Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
        ->whereIn('sellers.id', $otherSellers)
        ->sum('expenses.value');
    echo "3. Expenses for OTHER companies: " . number_format($expensesOther, 2) . "\n";
}

// Check if there are expenses with users that ARE NOT sellers
$noSellerTotal = Expense::leftJoin('sellers', 'expenses.user_id', '=', 'sellers.user_id')
    ->whereNull('sellers.id')
    ->whereNull('expenses.deleted_at')
    ->sum('expenses.value');
echo "4. Expenses by users with NO seller record: " . number_format($noSellerTotal, 2) . "\n";
