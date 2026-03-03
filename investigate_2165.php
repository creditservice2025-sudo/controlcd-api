<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 2165;

$output = "============================\nCREDIT 2165 DETAILS\n============================\n";

$credit = \App\Models\Credit::find($creditId);
if (!$credit) {
    die("Credit not found!\n");
}
$output .= "CREDIT:\nTotal: {$credit->total_amount} | Remaining: {$credit->remaining_amount} | Status: {$credit->status}\n\n";

$output .= "--- INSTALLMENTS ---\n";
$installments = \App\Models\Installment::where('credit_id', $creditId)->get();
foreach ($installments as $i) {
    $output .= "ID: {$i->id} | Quota: {$i->quota_amount} | Paid: {$i->paid_amount} | Status: {$i->status}\n";
}
$output .= "\n";

$output .= "--- PAYMENTS ---\n";
$payments = \App\Models\Payment::where('credit_id', $creditId)->orderBy('id')->get();
foreach ($payments as $p) {
    $output .= "ID: {$p->id} | Amount: {$p->amount} | Unapplied: {$p->unapplied_amount} | Status: {$p->status}\n";
}
$output .= "\n";

$output .= "--- PAYMENT_INSTALLMENTS ---\n";
$paymentIds = $payments->pluck('id');
$paymentInstallments = \App\Models\PaymentInstallment::whereIn('payment_id', $paymentIds)->orderBy('id')->get();
foreach ($paymentInstallments as $pi) {
    $output .= "ID: {$pi->id} | PMT ID: {$pi->payment_id} | INST ID: {$pi->installment_id} | Applied: {$pi->applied_amount}\n";
}
$output .= "\n";

file_put_contents('credit_2165_dump.txt', $output);
echo "Dumped to credit_2165_dump.txt\n";
