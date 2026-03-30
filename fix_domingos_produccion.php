<?php
/**
 * SCRIPT PARA CORREGIR CRÉDITOS CON CUOTAS EN DOMINGOS (HOTFIX DEVOPS)
 * Ejecución: php fix_domingos_produccion.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Installment;
use App\Services\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "=========================================================\n";
echo "   INICIANDO PARCHE DE CUOTAS DOMINICALES (PRODUCCIÓN)\n";
echo "=========================================================\n\n";

try {
    DB::beginTransaction();

    $creditService = app(CreditService::class);

    // 1. Identificar estrictamente los créditos afectados (con domingos programados y array vacío [])
    $affectedCreditIds = Installment::whereRaw('DAYOFWEEK(due_date) = 1')
        ->whereIn('credit_id', function($query) {
            $query->select('id')->from('credits')
                ->where('status', 'Vigente')
                ->where('excluded_days', '[]');
        })
        ->distinct()
        ->pluck('credit_id');

    $total = count($affectedCreditIds);
    echo "Créditos enconctrados a parchear: $total\n";

    if ($total === 0) {
        echo "No hay créditos con ese problema. Terminando con éxito.\n";
        DB::rollBack();
        exit(0);
    }

    $successCount = 0;
    $errorCount = 0;

    foreach ($affectedCreditIds as $index => $creditId) {
        try {
            $credit = Credit::find($creditId);
            if (!$credit) continue;

            echo "Procesando ".($index + 1)."/$total - Crédito ID: $credit->id ... ";

            // 2. Modificar el campo en la BD al valor por defecto
            $credit->excluded_days = json_encode(['Domingo']);
            $credit->save(); // El evento 'saving' del modelo Credit lo asegurará, pero lo hacemos explícito también

            // 3. Re-agendar usando el motor oficial de Marand (CreditService)
            // Cuidado: Le pasamos la 'first_quota_date' original del crédito para no mover el comienzo.
            // Si tiene notas o zona horaria, podemos enviarlo
            $response = $creditService->updateCreditSchedule(
                $credit->id,
                $credit->first_quota_date,
                'America/Lima', // asumimos default timezone del servicio
                'Corrección automática DevOps: exclusión de domingos'
            );

            $responseContent = json_decode($response->getContent());
            // Validar que el servicio devolviera 200 en su data envelope
            if (isset($responseContent->success) && $responseContent->success) {
                echo "CORREGIDO OK\n";
                $successCount++;
            } else {
                echo "FALLO durante actualización: " . json_encode($responseContent) . "\n";
                $errorCount++;
            }

        } catch (\Exception $e) {
            echo "ERROR CRITICO. ID $creditId: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }

    echo "\n=========================================================\n";
    echo "Resumen:\n";
    echo "- Exitosos: $successCount\n";
    echo "- Errores:  $errorCount\n";
    echo "=========================================================\n";

    if ($errorCount === 0) {
        DB::commit();
        echo "Parche finalizado y GUARDADO EN PRODUCCIÓN en la BD.\n\n";
    } else {
        DB::rollBack();
        echo "CUIDADO: Se abortó porque hubo $errorCount errores. Revise el log.\n\n";
    }

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n[ERROR GENERAL]: No se pudo correr el script: " . $e->getMessage() . "\n";
}
