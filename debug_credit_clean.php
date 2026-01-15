<?php

// Standalone execution script for Laravel
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
echo "### ANALISIS DETALLADO CREDITO #$creditId ###\n\n";

$credit = Credit::with(['client', 'seller'])->find($creditId);
if (!$credit) {
    echo "ERROR: Crédito no encontrado.\n";
    exit;
}

echo "INFO CREDITO:\n";
echo "- Cliente: " . ($credit->client->name ?? 'N/A') . "\n";
echo "- Valor Crédito (desembolso): " . $credit->credit_value . "\n";
echo "- Valor Total (con interés): " . $credit->total_amount . "\n";
echo "- Saldo Pendiente (Model): " . $credit->remaining_amount . "\n";
echo "- Estado: " . $credit->status . "\n";
echo "------------------------------------------\n\n";

echo "CUOTAS (installments):\n";
$installments = Installment::where('credit_id', $creditId)->orderBy('quota_number')->get();
foreach ($installments as $inst) {
    echo "ID: {$inst->id} | #{$inst->quota_number} | Vence: {$inst->due_date} | Monto: {$inst->quota_amount} | Pagado: {$inst->paid_amount} | Estado: {$inst->status} | Borrado: " . ($inst->deleted_at ?: 'No') . "\n";
}
echo "------------------------------------------\n\n";

echo "PAGOS (payments) Y SUS VINCULOS:\n";
$payments = Payment::where('credit_id', $creditId)->whereNull('deleted_at')->orderBy('payment_date')->get();
foreach ($payments as $pay) {
    echo "PAGO ID: {$pay->id} | Fecha: {$pay->payment_date} | Monto Pago: {$pay->amount} | Estado: {$pay->status}\n";
    
    $links = PaymentInstallment::where('payment_id', $pay->id)->get();
    if ($links->isEmpty()) {
        echo "   [!] SIN VINCULOS A CUOTAS\n";
    } else {
        foreach ($links as $link) {
            echo "   -> Link ID: {$link->id} | Cuota ID: {$link->installment_id} | Monto Aplicado: {$link->applied_amount} | Borrado: " . ($link->deleted_at ?: 'No') . "\n";
        }
    }
}
echo "------------------------------------------\n\n";

$totalPayments = Payment::where('credit_id', $creditId)->whereNull('deleted_at')->sum('amount');
$totalApplied = PaymentInstallment::whereIn('payment_id', $payments->pluck('id'))->sum('applied_amount');
$totalQuotaPaid = Installment::where('credit_id', $creditId)->sum('paid_amount');

echo "RESUMEN MATEMATICO:\n";
echo "- Total en tabla 'payments': $totalPayments\n";
echo "- Total aplicado en 'payment_installments': $totalApplied\n";
echo "- Total reflejado en 'installments.paid_amount': $totalQuotaPaid\n";

if ($totalPayments != $totalApplied) {
    echo "[!!!] DISCREPANCIA: El pago total no coincide con lo aplicado a las cuotas.\n";
}
if ($totalApplied != $totalQuotaPaid) {
     echo "[!!!] DISCREPANCIA: Lo aplicado no coincide con el paid_amount de las cuotas.\n";
}
