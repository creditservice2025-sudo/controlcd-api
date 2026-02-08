<?php

use App\Models\Credit;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Columns in 'credits' table:\n";
print_r(Schema::getColumnListing('credits'));
echo "\n";

$creditId = 708;
echo "Searching for Credit ID: $creditId\n";

$credit = Credit::with(['client', 'installments', 'payments'])->find($creditId);

if (!$credit) {
    echo "Credit ID {$creditId} not found.\n";
    
    // Try generic search if ID fails
    $c = Credit::where('id', 708)->first();
    if ($c) echo "Found via where('id', 708)\n";
    else echo "Not found via where('id') either.\n";

    exit;
}

echo "Credit ID: {$credit->id}\n";
echo "Client: {$credit->client->name} {$credit->client->last_name}\n";
echo "Status: {$credit->status}\n";
echo "Credit Value: {$credit->credit_value}\n";
echo "Total Amount (Column): " . ($credit->total_amount ?? 'NULL') . "\n";
echo "Remaining Amount (Column): " . ($credit->remaining_amount ?? 'NULL') . "\n";
echo "Total Interest: {$credit->total_interest}\n";
echo "Number Installments: {$credit->number_installments}\n";

echo "\n--- INSTALLMENTS ({$credit->installments->count()}) ---\n";
$totalQuota = 0;
$totalPaidInstallment = 0;
foreach ($credit->installments as $inst) {
    echo "ID: {$inst->id} | #{$inst->quota_number} | Amount: {$inst->quota_amount} | Paid: {$inst->paid_amount} | Status: {$inst->status} | Due: {$inst->due_date}\n";
    $totalQuota += $inst->quota_amount;
    $totalPaidInstallment += $inst->paid_amount;
}
echo "Total Expected (Sum Quotas): $totalQuota\n";
echo "Total Paid (Sum Installment Paid): $totalPaidInstallment\n";

echo "\n--- PAYMENTS ({$credit->payments->count()}) ---\n";
$totalPayments = 0;
foreach ($credit->payments as $p) {
    echo "ID: {$p->id} | Amount: {$p->amount} | Date: {$p->payment_date} | Status: {$p->status}\n";
    if ($p->status != 'Anulado') {
        $totalPayments += $p->amount;
    }
}
echo "Total Valid Payments: $totalPayments\n";

$pending = $credit->pendingAmount();
echo "Calculated Pending Amount (Method): {$pending}\n";
