<?php

use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Iniciando sincronización de historial de abonos...\n";

$installments = CollectionInstallment::where('paid_amount', '>', 0)->get();
$count = 0;

foreach ($installments as $inst) {
    // Check if it already has payments recorded in the audit table
    $hasPayments = CollectionPayment::where('credit_id', $inst->credit_id)
        ->where('installment_number', $inst->installment_number)
        ->where('company_id', $inst->company_id)
        ->exists();

    if (!$hasPayments) {
        echo "Sincronizando Cuota #{$inst->installment_number} del Crédito #{$inst->credit_id} (Cliente ID: {$inst->credit->client_id})\n";

        $newId = (int) CollectionPayment::withTrashed()
            ->where('company_id', $inst->company_id)
            ->max('id') + 1;

        CollectionPayment::create([
            'id' => $newId,
            'company_id' => $inst->company_id,
            'credit_id' => $inst->credit_id,
            'installment_number' => $inst->installment_number,
            'amount_paid' => $inst->paid_amount,
            'payment_date' => $inst->last_payment_at ? $inst->last_payment_at->toDateString() : Carbon::now()->toDateString(),
            'payment_method' => $inst->payment_method ?? 'Efectivo',
            'notes' => $inst->notes ?? 'Sincronización retroactiva',
            'voucher_path' => $inst->voucher_path,
            'recorded_at' => $inst->last_payment_at ?? Carbon::now(),
        ]);
        
        $count++;
    }
}

echo "\nSincronización completada. Se crearon {$count} registros de historial.\n";
