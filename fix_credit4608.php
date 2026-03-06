<?php
ini_set('memory_limit', '256M');

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Installment;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

$creditId = 4608;
$applyFix = in_array('--fix', $argv);

$credit = Credit::with('installments')->find($creditId);

if (!$credit) {
    echo "Crédito {$creditId} no encontrado.\n";
    exit(1);
}

echo "=== Crédito #{$credit->id} ===\n";
echo "total_amount en DB: {$credit->total_amount}\n";

// Calcular el total correcto desde las cuotas
$sumQuotas = round($credit->installments->sum('quota_amount'), 2);
echo "Suma de cuotas (total real): {$sumQuotas}\n\n";

// La cuota 4 está pendiente con $50.01
// El pago disponible es $49.99
// La diferencia correcta es $49.99 (cuota 4 debería ser $49.99 para cuadrar el total real de $200.02)
// Pero total_amount = 0.00 es el problema principal

// Cuota a corregir
$lastInstallment = $credit->installments
    ->where('status', 'Pendiente')
    ->sortBy('quota_number')
    ->first(); // La primera pendiente = cuota #4

// El pago con saldo no aplicado
$unappliedPayment = Payment::where('credit_id', $creditId)
    ->where('unapplied_amount', '>', 0)
    ->first();

if ($lastInstallment && $unappliedPayment) {
    echo "Cuota a corregir: #{$lastInstallment->quota_number}\n";
    echo "  Monto actual: {$lastInstallment->quota_amount}\n";
    echo "  Saldo disponible (pago #{$unappliedPayment->id}): {$unappliedPayment->unapplied_amount}\n\n";

    // El monto correcto de la cuota 4 es el saldo disponible ($49.99)
    // porque 50.01 * 3 + 49.99 = 200.02 (total real)
    $newAmount = $unappliedPayment->unapplied_amount;

    echo "Nueva cantidad propuesta para cuota #{$lastInstallment->quota_number}: {$newAmount}\n";

    if ($applyFix) {
        DB::beginTransaction();
        try {
            $lastInstallment->quota_amount = $newAmount;
            $lastInstallment->save();
            echo "  [OK] Cuota actualizada.\n";

            DB::commit();

            // Re-aplicar el pago
            $paymentService = app(PaymentService::class);
            $paymentService->reapplyPayments($creditId);
            echo "  [OK] Saldo re-aplicado.\n";

            // Verificar resultado
            $credit->load('installments');
            $payment = Payment::where('credit_id', $creditId)->find($unappliedPayment->id);

            echo "\n=== RESULTADO FINAL ===\n";
            foreach ($credit->installments->sortBy('quota_number') as $inst) {
                echo "  #{$inst->quota_number}: \${$inst->quota_amount} | Pagado: \${$inst->paid_amount} | {$inst->status}\n";
            }
            echo "  Saldo no aplicado restante: \${$payment->fresh()->unapplied_amount}\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "  [ERROR] " . $e->getMessage() . "\n";
        }
    } else {
        echo "\nEjecuta con --fix para aplicar los cambios:\n";
        echo "  php fix_credit4608.php --fix\n";
    }
} else {
    echo "No se encontraron cuotas pendientes o pagos con saldo no aplicado.\n";
}
