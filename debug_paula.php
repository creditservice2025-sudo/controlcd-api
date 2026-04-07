<?php

use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionPayment;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 2;
echo "Investigando Crédito #{$creditId}...\n";

$installments = CollectionInstallment::where('credit_id', $creditId)->get();
echo "Total cuotas encontradas: " . $installments->count() . "\n";

foreach ($installments as $inst) {
    echo "Cuota #{$inst->installment_number}: Amount: {$inst->amount}, Paid: {$inst->paid_amount}, Status: {$inst->status}\n";
    
    $paymentsCount = CollectionPayment::where('credit_id', $inst->credit_id)
        ->where('installment_number', $inst->installment_number)
        ->count();
    
    echo "  -> Pagos en historial: {$paymentsCount}\n";
}
