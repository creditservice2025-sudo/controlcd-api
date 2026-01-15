<?php

use App\Models\Installment;
use App\Models\PaymentInstallment;
use App\Models\Payment;
use App\Models\Liquidation;
use Carbon\Carbon;

// Installment ID from user screenshot
$installmentId = 15083;

$installment = Installment::with(['credit.seller', 'payments'])->find($installmentId);

if (!$installment) {
    echo "Installment not found\n";
    exit;
}

echo "Testing simulation for Installment #{$installmentId}\n";
echo "Credit ID: {$installment->credit_id}\n";
echo "Seller ID: {$installment->credit->seller_id}\n";

// Get affected liquidations logic
$affectedDates = [];
$paymentInstallments = PaymentInstallment::where('installment_id', $installment->id)->get();

echo "Payment Installments found: " . count($paymentInstallments) . "\n";

foreach ($paymentInstallments as $pi) {
    $payment = Payment::find($pi->payment_id);
    if ($payment) {
        echo "Payment #{$payment->id}, Amount: {$pi->applied_amount}, Business Date: " . ($payment->business_date ?? 'NULL') . "\n";
        if ($payment->business_date) {
            $affectedDates[] = $payment->business_date;
        }
    }
}

if (empty($affectedDates)) {
    echo "No affected dates found!\n";
    exit;
}

$liquidations = Liquidation::whereIn('date', $affectedDates)
    ->where('seller_id', $installment->credit->seller_id)
    ->get();

echo "Liquidations found: " . count($liquidations) . "\n";
foreach ($liquidations as $liq) {
    echo "Liquidation ID: {$liq->id}, Date: {$liq->date}\n";
    
    // Test getPaidAmountForInstallment logic
    $targetDate = $liq->date instanceof Carbon ? $liq->date->toDateString() : substr($liq->date, 0, 10);
    echo "Target Date (normalized): {$targetDate}\n";
    
    $total = 0;
    foreach ($paymentInstallments as $pi) {
        $p = Payment::find($pi->payment_id);
        if ($p && $p->business_date) {
            $pDate = $p->business_date instanceof Carbon ? $p->business_date->toDateString() : substr($p->business_date, 0, 10);
            echo " - Comparing Payment Date {$pDate} with Target Date {$targetDate}\n";
            if ($pDate === $targetDate) {
                $total += $pi->applied_amount;
            }
        }
    }
    echo "Total calculated for this date: {$total}\n";
}
