<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Payment;
use App\Models\Installment;
use App\Models\PaymentInstallment;
use Illuminate\Support\Facades\DB;

$creditId = 2252;
echo "### ANALISIS DE INTEGRIDAD: CREDITO #$creditId ###\n\n";

$credit = Credit::with(['client', 'seller'])->find($creditId);
if (!$credit) {
    echo "ERROR: Crédito no encontrado.\n";
    exit;
}

echo "RESUMEN CREDITO:\n";
echo "- Total de Crécito (Cuotas): " . Installment::where('credit_id', $creditId)->withTrashed()->sum('quota_amount') . "\n";
echo "- Saldo Pendiente (Model): " . $credit->remaining_amount . "\n";
echo "------------------------------------------\n\n";

echo "DETALLE DE CUOTAS (Incluyendo eliminadas):\n";
$installments = Installment::where('credit_id', $creditId)->withTrashed()->orderBy('quota_number')->get();
foreach ($installments as $inst) {
    $deletedMsg = $inst->deleted_at ? "[ELIMINADA: {$inst->deleted_at}]" : "[ACTIVA]";
    echo "ID: {$inst->id} | #{$inst->quota_number} | Monto: {$inst->quota_amount} | Pagado: {$inst->paid_amount} | Estado: {$inst->status} $deletedMsg\n";
}
echo "------------------------------------------\n\n";

echo "DETALLE DE PAGOS Y DISTRIBUCION (payment_installments):\n";
$payments = Payment::where('credit_id', $creditId)->withTrashed()->orderBy('payment_date')->get();
foreach ($payments as $pay) {
    $deletedMsg = $pay->deleted_at ? "[ANULADO: {$pay->deleted_at}]" : "[VALIDO]";
    echo "PAGO ID: {$pay->id} | Fecha: {$pay->payment_date} | Monto: {$pay->amount} | $deletedMsg\n";
    
    $links = PaymentInstallment::where('payment_id', $pay->id)->withTrashed()->get();
    if ($links->isEmpty()) {
        echo "   [!] HÚERFANO: No tiene registros en payment_installments.\n";
    } else {
        foreach ($links as $link) {
            $lDeleted = $link->deleted_at ? "[ELIMINADO]" : "[VIVO]";
            echo "   -> Link ID: {$link->id} | Cuota ID: {$link->installment_id} | Distribución: {$link->applied_amount} $lDeleted\n";
        }
    }
}
echo "------------------------------------------\n\n";

echo "AUDITORIA DE ABONOS:\n";
// Sum of all non-deleted payments
$actualCash = Payment::where('credit_id', $creditId)->whereNull('deleted_at')->sum('amount');
// Sum of all non-deleted links
$allocatedCash = PaymentInstallment::whereIn('payment_id', Payment::where('credit_id', $creditId)->whereNull('deleted_at')->pluck('id'))
    ->whereNull('deleted_at')->sum('applied_amount');

echo "- Dinero real en 'payments': $actualCash\n";
echo "- Dinero asignado en 'payment_installments': $allocatedCash\n";
echo "- Diferencia (Abono sin asignar): " . ($actualCash - $allocatedCash) . "\n";
