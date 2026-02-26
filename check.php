<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PaymentInstallment;
use App\Models\Payment;
use App\Models\Installment;

$paymentId = 61012; // From user screenshot
$payment = Payment::find($paymentId);

if (!$payment) {
    echo "Payment 61012 no encontrado.\n";
} else {
    echo "Payment 61012 amount: {$payment->amount}, unapplied_amount: {$payment->unapplied_amount}\n";
    
    $pis = PaymentInstallment::withTrashed()->where('payment_id', $paymentId)->get();
    echo "Found " . $pis->count() . " payment_installments (including soft deleted).\n";
    if ($pis->count() > 0) {
        foreach ($pis as $pi) {
            echo " - PI ID {$pi->id}: Installment ID {$pi->installment_id}, applied: {$pi->applied_amount}, deleted_at: {$pi->deleted_at}\n";
        }
    }
}

$installments = Installment::where('credit_id', 12825)->get();
echo "Installments para credit 12825: Total " . $installments->count() . "\n";
echo "Total paid_amount de Installments: " . $installments->sum('paid_amount') . "\n";

