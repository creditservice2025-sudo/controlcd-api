<?php

use App\Models\Credit;
use App\Models\Payment;
use App\Models\Installment;
use App\Models\PaymentInstallment;

$creditId = 2252;
echo "Analizando Crédito #$creditId\n";

$credit = Credit::with(['client', 'seller'])->find($creditId);

if (!$credit) {
    echo "Crédito no encontrado.\n";
    exit;
}

echo "Saldo Pendiente (Credit Model): " . $credit->remaining_amount . "\n";
echo "Estado del Crédito: " . $credit->status . "\n\n";

echo "--- CUOTAS (installments) ---\n";
$installments = Installment::where('credit_id', $creditId)->get();
foreach ($installments as $inst) {
    echo "ID: {$inst->id} | Cuota #: {$inst->quota_number} | Monto: {$inst->quota_amount} | Pagado: {$inst->paid_amount} | Estado: {$inst->status} | Borrado: " . ($inst->deleted_at ?: 'No') . "\n";
}

echo "\n--- PAGOS (payments) ---\n";
$payments = Payment::where('credit_id', $creditId)->whereNull('deleted_at')->get();
foreach ($payments as $pay) {
    echo "ID: {$pay->id} | Fecha: {$pay->payment_date} | Monto: {$pay->amount} | Estado: {$pay->status} | Fecha Negocio: {$pay->business_date}\n";
    
    $links = PaymentInstallment::where('payment_id', $pay->id)->get();
    foreach ($links as $link) {
        echo "   -> Link ID: {$link->id} | Installment ID: {$link->installment_id} | Monto Aplicado: {$link->applied_amount} | Borrado: " . ($link->deleted_at ?: 'No') . "\n";
    }
}

echo "\n--- PAGO TOTAL CALCULADO ---\n";
$totalPaid = Payment::where('credit_id', $creditId)->whereNull('deleted_at')->sum('amount');
echo "Total Pagado sumando payments: $totalPaid\n";

$totalQuotaPaid = Installment::where('credit_id', $creditId)->sum('paid_amount');
echo "Total Pagado sumando installments-paid_amount: $totalQuotaPaid\n";
