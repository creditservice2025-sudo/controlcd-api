<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Models\PaymentInstallment;

$creditId = 4531;
$credit = Credit::find($creditId); 

// Fallback search by number if ID not found directly
if (!$credit) {
    if (is_numeric($creditId)) { 
        // Maybe try to find by credit_no if ID fails? User said #004531
         $credit = Credit::where('credit_no', 'like', "%$creditId%")->first();
    }
}

if (!$credit) {
    echo "Credit $creditId not found.\n";
    exit;
}

echo "Fixing Credit {$credit->credit_no} (ID: {$credit->id})...\n";

try {
    \DB::beginTransaction();

    $installments = $credit->installments()->orderBy('quota_number')->get();
    
    foreach ($installments as $inst) {
        $inst->quota_amount = 24.00;
        $inst->paid_amount = 0;
        $inst->status = 'Pendiente';
        $inst->save();
    }
    
    echo "Reset " . $installments->count() . " installments to $24.00.\n";

    $payments = Payment::where('credit_id', $credit->id)->get();
    foreach ($payments as $payment) {
        PaymentInstallment::where('payment_id', $payment->id)->delete();
        $payment->unapplied_amount = $payment->amount;
        $payment->status = 'Pagado';
        $payment->save();
    }
    
    echo "Reset " . $payments->count() . " payments.\n";

    $paymentService = app(PaymentService::class);
    $result = $paymentService->reapplyPayments($credit->id);
    
    echo "Reapply Result: " . $result->getContent() . "\n";

    \DB::commit();
    echo "Done.\n";

} catch (\Exception $e) {
    \DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
