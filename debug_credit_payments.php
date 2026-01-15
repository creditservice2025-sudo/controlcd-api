<?php

use App\Models\Credit;
use App\Models\Installment;

$creditId = 2941;
$credit = Credit::find($creditId);

if (!$credit) {
    echo "Credit #$creditId not found.\n";
    exit;
}

echo "Credit #$creditId found.\n";
echo "Value: " . $credit->credit_value . "\n";
echo "Remaining: " . $credit->remaining_amount . "\n";

$installments = Installment::with(['payments.payment'])
    ->where('credit_id', $creditId)
    ->orderBy('quota_number')
    ->get();

echo "Installments found: " . $installments->count() . "\n";

foreach ($installments as $inst) {
    echo "Inst #{$inst->quota_number} (ID: {$inst->id}): Amount: {$inst->quota_amount}, Paid: {$inst->paid_amount}, Status: {$inst->status}\n";
    if ($inst->payments->count() > 0) {
        echo "  Payments:\n";
        foreach ($inst->payments as $pi) {
            echo "    - PI ID: {$pi->id}, Applied: {$pi->applied_amount}\n";
            if ($pi->payment) {
                 echo "      -> Payment ID: {$pi->payment->id}, Date: {$pi->payment->business_date}, Total Pay Amount: {$pi->payment->amount}\n";
            } else {
                 echo "      -> Payment relation is NULL\n";
            }
        }
// ... existing code ...
    } else {
        echo "  No payments linked.\n";
    }
}

echo "\n--- RAW PAYMENTS CHECK ---\n";
$rawPayments = \App\Models\Payment::where('credit_id', $creditId)->get();
echo "Total Payments found: " . $rawPayments->count() . "\n";
foreach ($rawPayments as $p) {
    echo "Payment ID: {$p->id}, Date: {$p->business_date}, Amount: {$p->amount}, Unapplied: {$p->unapplied_amount}\n";
    if ($p->unapplied_amount > 0) {
        echo "  WARNING: This payment is not fully applied to installments!\n";
    }
}
