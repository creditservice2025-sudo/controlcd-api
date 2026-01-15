<?php

use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentInstallment;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = 2233;
$c = Credit::find($id);
if (!$c) {
    echo "Credit $id not found\n";
    exit;
}

printf("Credit #%d\n", $id);
printf("Total Amount: %.2f\n", $c->total_amount);
printf("Remaining Amount: %.2f\n", $c->remaining_amount);
printf("Status: %s\n", $c->status);

echo "\nInstallments:\n";
$is = Installment::where('credit_id', $id)->get();
foreach ($is as $i) {
    printf("ID: %d | Quota: %d | Status: %s | Paid: %.2f/%.2f | Due: %s\n", 
        $i->id, $i->quota_number, $i->status, $i->paid_amount, $i->quota_amount, $i->due_date);
}

echo "\nPayments:\n";
$ps = Payment::where('credit_id', $id)->get();
foreach ($ps as $p) {
    printf("Pay ID: %d | Status: %s | Amount: %.2f | Unapplied: %.2f\n", 
        $p->id, $p->status, $p->amount, $p->unapplied_amount);
}

echo "\nPaymentInstallment links:\n";
$pis = PaymentInstallment::whereIn('installment_id', $is->pluck('id'))->get();
foreach ($pis as $pi) {
    printf("PI ID: %d | Payment: %d | Installment: %d | Applied: %.2f\n", 
        $pi->id, $pi->payment_id, $pi->installment_id, $pi->applied_amount);
}
