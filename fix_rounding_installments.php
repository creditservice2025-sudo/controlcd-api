<?php
/**
 * Script: fix_rounding_installments.php
 *
 * Corrige créditos donde la suma de cuotas no coincide con total_amount
 * por causa del redondeo matemático.
 *
 * Uso:
 *   php fix_rounding_installments.php          → Solo diagnóstico (NO modifica nada)
 *   php fix_rounding_installments.php --fix    → Aplica la corrección y re-aplica pagos
 */

ini_set('memory_limit', '512M');

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Installment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

$applyFix = in_array('--fix', $argv);

$line = str_repeat('=', 70);
echo "{$line}\n";
echo " FIX REDONDEO DE CUOTAS - " . ($applyFix ? "MODO CORRECCIÓN" : "MODO DIAGNÓSTICO") . "\n";
echo " Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "{$line}\n\n";

if (!$applyFix) {
    echo " ⚠  Esto es solo un diagnóstico. Usa --fix para aplicar cambios.\n\n";
}

// Solo créditos con total_amount > 0 en estados activos
$credits = Credit::whereIn('status', ['Vigente', 'Vencido'])
    ->where('total_amount', '>', 0)
    ->with(['installments', 'client'])
    ->get();

$affectedIds = [];
$fixedCount  = 0;
$errorCount  = 0;

foreach ($credits as $credit) {
    $sumQuotas = round($credit->installments->sum('quota_amount'), 2);
    $diff      = round($sumQuotas - $credit->total_amount, 2);

    // Saltar si no hay diferencia o no hay cuotas
    if ($credit->installments->isEmpty() || abs($diff) < 0.001) {
        continue;
    }

    $affectedIds[] = $credit->id;

    echo "┌─ Crédito #{$credit->id} | {$credit->client->name}\n";
    echo "│  Total:       \${$credit->total_amount}\n";
    echo "│  Suma cuotas: \${$sumQuotas}\n";
    echo "│  Diferencia:  \${$diff}\n";

    $lastInstallment = $credit->installments->sortByDesc('quota_number')->first();
    $newAmount       = round($lastInstallment->quota_amount - $diff, 2);

    echo "│  Ajustar cuota #{$lastInstallment->quota_number}: \${$lastInstallment->quota_amount} → \${$newAmount}\n";

    if ($applyFix) {
        DB::beginTransaction();
        try {
            $lastInstallment->quota_amount = $newAmount;
            $lastInstallment->save();

            DB::commit();

            // Re-aplicar saldos pendientes al crédito
            app(PaymentService::class)->reapplyPayments($credit->id);

            // Verificar resultado
            $credit->load('installments');
            $newSum = round($credit->installments->sum('quota_amount'), 2);

            echo "│  Suma final:  \${$newSum} (" . ($newSum == $credit->total_amount ? '✓ Cuadrado' : '✗ Revisar') . ")\n";
            echo "└─ [OK] Corregido.\n\n";

            $fixedCount++;
        } catch (\Exception $e) {
            DB::rollBack();
            echo "└─ [ERROR] " . $e->getMessage() . "\n\n";
            $errorCount++;
        }
    } else {
        echo "└─ (Sin cambios — usa --fix para aplicar)\n\n";
    }
}

echo "{$line}\n";
echo " RESULTADO FINAL\n";
echo "{$line}\n";
echo " Créditos analizados (total_amount > 0): {$credits->count()}\n";
echo " Créditos con diferencia de redondeo:    " . count($affectedIds) . "\n";

if (count($affectedIds) > 0) {
    echo " IDs afectados: #" . implode(', #', $affectedIds) . "\n";
}

if ($applyFix) {
    echo " Correcciones exitosas: {$fixedCount}\n";
    echo " Errores:               {$errorCount}\n";
} else {
    echo "\n Ejecuta con --fix para aplicar los cambios:\n";
    echo "   php fix_rounding_installments.php --fix\n";
}

echo "{$line}\n";
