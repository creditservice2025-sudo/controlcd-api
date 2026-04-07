<?php

use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionPayment;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 2;
echo "Detallando Pagos del Crédito #{$creditId}...\n";

$payments = CollectionPayment::where('credit_id', $creditId)->get();
echo "Total pagos encontrados: " . $payments->count() . "\n";

foreach ($payments as $p) {
    echo "Pago ID: {$p->id} | Installment: {$p->installment_number} | Amount: {$p->amount_paid} | Date: {$p->payment_date} | Recorded: {$p->recorded_at} | Method: {$p->payment_method}\n";
}
