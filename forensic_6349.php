<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Installment;

$paymentId = 6349;
echo "### AUDITORIA FORENSE PAGO #$paymentId ###\n";

$payment = Payment::withTrashed()->find($paymentId);
if (!$payment) {
    echo "PAGO NO ENCONTRADO\n";
    exit;
}

echo "DATOS PAGO:\n";
echo "ID: {$payment->id}\n";
echo "Monto: {$payment->amount}\n";
echo "Unapplied: {$payment->unapplied_amount}\n";
echo "Status: {$payment->status}\n";
echo "Creado: {$payment->created_at}\n";
echo "Actualizado: {$payment->updated_at}\n";
echo "Borrado: " . ($payment->deleted_at ?: 'NO') . "\n";
echo "------------------------------------------\n";

echo "VINCULOS (payment_installments):\n";
$links = PaymentInstallment::withTrashed()->where('payment_id', $paymentId)->get();
foreach ($links as $l) {
    $inst = Installment::withTrashed()->find($l->installment_id);
    echo "LINK ID: {$l->id}\n";
    echo "  -> Aplicado a Cuota ID: {$l->installment_id} (#" . ($inst->quota_number ?? '?') . ")\n";
    echo "  -> Monto Aplicado: {$l->applied_amount}\n";
    echo "  -> Creado: {$l->created_at}\n";
    echo "  -> Borrado: " . ($l->deleted_at ?: 'NO') . "\n";
}
echo "------------------------------------------\n";

echo "HISTORIAL DE LA CUOTA 15127 (A la que se aplicó los 80):\n";
$inst1 = Installment::withTrashed()->find(15127);
if ($inst1) {
    echo "ID: {$inst1->id}\n";
    echo "Monto Cuota: {$inst1->quota_amount}\n";
    echo "Pagado actualmente: {$inst1->paid_amount}\n";
    echo "Borrado: " . ($inst1->deleted_at ?: 'NO') . "\n";
}
echo "### FIN ANALISIS ###\n";
