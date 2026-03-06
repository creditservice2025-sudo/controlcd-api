<?php
ini_set('memory_limit', '256M');

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;

$creditId = 4608;
$credit = Credit::with('installments')->find($creditId);

if (!$credit) {
    echo "Crédito {$creditId} no encontrado.\n";
    exit(1);
}

echo "=== Crédito #{$credit->id} ===\n";
echo "Total Amount: {$credit->total_amount}\n\n";

$sumQuotas = round($credit->installments->sum('quota_amount'), 2);
$diff = round($sumQuotas - $credit->total_amount, 2);
echo "Suma de cuotas: {$sumQuotas}  |  Diferencia: {$diff}\n\n";

echo "Cuotas:\n";
foreach ($credit->installments->sortBy('quota_number') as $inst) {
    echo "  #{$inst->quota_number}: \${$inst->quota_amount} | Pagado: \${$inst->paid_amount} | Estado: {$inst->status}\n";
}

$payments = Payment::where('credit_id', $creditId)->get();
echo "\nPagos:\n";
foreach ($payments as $p) {
    echo "  ID: {$p->id} | Monto: \${$p->amount} | Saldo no aplicado: \${$p->unapplied_amount} | Estado: {$p->status}\n";
}
