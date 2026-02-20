<?php
ini_set('memory_limit', '512M');

/**
 * Script: fix_installments.php
 *
 * Propósito: Detectar y corregir créditos vigentes donde la suma de las cuotas
 * no coincide con el total_amount a causa del redondeo.
 *
 * Uso:
 *   php fix_installments.php          -> Solo diagnóstico (no modifica nada)
 *   php fix_installments.php --fix    -> Aplica las correcciones
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Installment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

$applyFix = in_array('--fix', $argv);

echo "=================================================\n";
echo " DIAGNÓSTICO DE CUOTAS - DIFERENCIAS DE REDONDEO\n";
echo "=================================================\n";
if ($applyFix) {
    echo " MODO: CORRECCIÓN ACTIVA\n";
} else {
    echo " MODO: SOLO DIAGNÓSTICO (usa --fix para corregir)\n";
}
echo "=================================================\n\n";

$credits = Credit::whereIn('status', ['Vigente', 'Vencido'])
    ->with(['installments', 'client'])
    ->get();

$affected = [];
$fixed = 0;
$errors = 0;

foreach ($credits as $credit) {
    $sumQuotas = round($credit->installments->sum('quota_amount'), 2);
    $diff = round($sumQuotas - $credit->total_amount, 2);

    if (abs($diff) > 0.001) {
        $affected[] = $credit;
        echo "AFECTADO:\n";
        echo "  - Crédito ID : {$credit->id}\n";
        echo "  - Cliente    : {$credit->client->name}\n";
        echo "  - Total      : {$credit->total_amount}\n";
        echo "  - Suma Cuotas: {$sumQuotas}\n";
        echo "  - Diferencia : {$diff}\n";

        // Detalle de cuotas
        foreach ($credit->installments->sortBy('quota_number') as $inst) {
            echo "    Cuota #{$inst->quota_number}: \${$inst->quota_amount} [{$inst->status}]\n";
        }

        if ($applyFix) {
            DB::beginTransaction();
            try {
                // Ajustar la ÚLTIMA cuota
                $lastInstallment = $credit->installments->sortByDesc('quota_number')->first();
                $oldAmount = $lastInstallment->quota_amount;
                $newAmount = round($oldAmount - $diff, 2);

                echo "  -> CORRIGIENDO: Cuota #{$lastInstallment->quota_number}: {$oldAmount} -> {$newAmount}\n";

                $lastInstallment->quota_amount = $newAmount;
                $lastInstallment->save();

                DB::commit();

                // Reaplicar pagos para que el saldo flotante se pegue a la cuota corregida
                echo "  -> Re-aplicando saldos pendientes...\n";
                $paymentService = app(PaymentService::class);
                $paymentService->reapplyPayments($credit->id);

                echo "  [OK] Corregido exitosamente.\n";
                $fixed++;
            } catch (\Exception $e) {
                DB::rollBack();
                echo "  [ERROR] " . $e->getMessage() . "\n";
                $errors++;
            }
        }

        echo "\n";
    }
}

echo "=================================================\n";
echo " RESULTADO FINAL\n";
echo "=================================================\n";
echo " Total créditos analizados : " . $credits->count() . "\n";
echo " Créditos afectados        : " . count($affected) . "\n";

if ($applyFix) {
    echo " Correcciones aplicadas    : {$fixed}\n";
    echo " Errores                   : {$errors}\n";
} else {
    if (count($affected) > 0) {
        echo "\n Ejecuta con --fix para aplicar las correcciones:\n";
        echo "   php fix_installments.php --fix\n";
    } else {
        echo "\n No se encontraron créditos con diferencias de redondeo.\n";
    }
}
echo "=================================================\n";
