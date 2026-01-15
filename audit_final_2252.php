<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Payment;
use App\Models\PaymentInstallment;

$creditId = 2252;
echo "### AUDIT FINAL 2252 ###\n";

$payments = Payment::withTrashed()->where('credit_id', $creditId)->orderBy('id')->get();
foreach ($payments as $p) {
    printf("PAYMENT ID: %d | AMT: %.2f | STATUS: %s | UNAPP: %.2f | DEL: %s\n", 
            $p->id, $p->amount, $p->status, $p->unapplied_amount, $p->deleted_at ?: 'NO');
    
    $links = PaymentInstallment::withTrashed()->where('payment_id', $p->id)->get();
    foreach ($links as $l) {
        printf("  -> LINK ID: %d | INST: %d | APPLIED: %.2f | DEL: %s\n", 
                $l->id, $l->installment_id, $l->applied_amount, $l->deleted_at ?: 'NO');
    }
}
echo "### END ###\n";
