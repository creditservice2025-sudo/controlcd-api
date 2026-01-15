<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Payment;
use App\Models\PaymentInstallment;
use Illuminate\Support\Facades\DB;

$creditId = 2252;
echo "AUDITORIA DE PAGOS PARA CREDITO #$creditId\n\n";

$payments = Payment::withTrashed()->where('credit_id', $creditId)->orderBy('created_at')->get();

foreach ($payments as $p) {
    echo "ID: {$p->id} | Monto: {$p->amount} | Status: {$p->status} | Unapplied: {$p->unapplied_amount} | Creado: {$p->created_at} | Borrado: " . ($p->deleted_at ?: 'NO') . "\n";
    
    $links = PaymentInstallment::withTrashed()->where('payment_id', $p->id)->get();
    foreach ($links as $l) {
        echo "   -> LINK ID: {$l->id} | Inst ID: {$l->installment_id} | Applied: {$l->applied_amount} | Link Borrado: " . ($l->deleted_at ?: 'NO') . "\n";
    }
}
