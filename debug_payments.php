<?php

use Illuminate\Support\Facades\DB;
use App\Models\Payment;
use Carbon\Carbon;

$sellerId = 18;
$date = '2025-12-23';
$timezone = 'America/Lima';

echo "Deep Dive Status Check for Seller $sellerId on $date ($timezone)\n";

// 1. Get ALL payments for this seller and business_date, grouped by status
$allPaymentsQuery = DB::table('payments')
    ->join('credits', 'payments.credit_id', '=', 'credits.id')
    ->select(
        'payments.status',
        DB::raw('COUNT(*) as count'),
        DB::raw('SUM(payments.amount) as total_amount')
    )
    ->whereNull('payments.deleted_at')
    ->where('payments.business_date', $date)
    ->where('credits.seller_id', $sellerId)
    ->groupBy('payments.status');

$results = $allPaymentsQuery->get();

echo "\n--- All Payments by Status ---\n";
$grandTotal = 0;
foreach ($results as $row) {
    echo "Status: " . ($row->status ?? 'NULL') . " | Count: {$row->count} | Total: {$row->total_amount}\n";
    $grandTotal += $row->total_amount;
}
echo "-----------------------------\n";
echo "Grand Total (All Statuses): $grandTotal\n";

// 2. Simulate LiquidationController Filter
$liquidationTotal = 0;
$liquidationStatuses = ['Pagado', 'Aprobado', 'Abonado'];
echo "\n--- LiquidationController Filter ---\n";
echo "Allowed Statuses: " . implode(', ', $liquidationStatuses) . "\n";

foreach ($results as $row) {
    if (in_array($row->status, $liquidationStatuses)) {
        $liquidationTotal += $row->total_amount;
    } else {
        echo "EXCLUDED: Status '{$row->status}' with amount {$row->total_amount}\n";
    }
}
echo "Liquidation Total: $liquidationTotal\n";

echo "\nDifference: " . ($grandTotal - $liquidationTotal) . "\n";
