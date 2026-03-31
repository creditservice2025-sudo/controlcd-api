<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Liquidation;
use App\Models\Seller;
use App\Services\LiquidationService;

$sellers = Seller::with('user')->get();
$alexisId = null;
foreach($sellers as $s) {
    if(stripos($s->user?->name ?? '', 'Alexis') !== false) {
        $alexisId = $s->id;
        echo "Found Alexis Seller ID: " . $alexisId . " (Name: " . $s->user->name . ")\n";
        break; // Assuming first one for test
    }
}

if ($alexisId) {
    echo "\nExtrayendo Liquidacion de Alexis para HOY (2026-03-30)...\n";
    $service = app(LiquidationService::class);
    // getLiquidationData($sellerId, $date, $userId, $timezone = null, $autoCreate = true)
    $data = $service->getLiquidationData($alexisId, '2026-03-30', Seller::find($alexisId)->user_id, 'America/Lima', false);
    
    echo "========= VALORES DE CAJA DEL DIA =========\n";
    echo "Ingresos (+):\n";
    echo "- Total Cobrado: $" . number_format($data['total_collected'], 2) . "\n";
    echo "- Total Ingresos: $" . number_format($data['total_income'], 2) . "\n";
    echo "- Base Entregada: $" . number_format($data['base_delivered'], 2) . "\n";
    echo "- Póliza: $" . number_format($data['poliza'], 2) . "\n";
    $sumIngresos = $data['total_collected'] + $data['total_income'] + $data['base_delivered'] + $data['poliza'];
    echo "  -> Subtotal Ingresos: $".number_format($sumIngresos, 2)."\n\n";

    echo "Egresos (-):\n";
    echo "- Créditos Nuevos: $" . number_format($data['new_credits'], 2) . "\n";
    echo "- Total Gastos: $" . number_format($data['total_expenses'], 2) . "\n";
    echo "- Total E. de Renovación (desembolsado neto): $" . number_format($data['total_renewal_disbursed'] ?? 0, 2) . "\n";
    echo "- Cartera Irrecuperable: $" . number_format($data['irrecoverable_credits'] ?? 0, 2) . "\n";
    $sumEgresos = $data['new_credits'] + $data['total_expenses'] + ($data['total_renewal_disbursed'] ?? 0) + ($data['irrecoverable_credits'] ?? 0);
    echo "  -> Subtotal Egresos: $".number_format($sumEgresos, 2)."\n\n";

    echo "=========== RESULTADO ===========\n";
    $calculated = $sumIngresos - $sumEgresos;
    echo "Caja del día Calculada Matemáticamente: $" . number_format($calculated, 2) . "\n";
    echo "Caja del día que devuelve la API (cash_collection): $" . number_format($data['cash_collection'], 2) . "\n";
    if (abs($calculated - 242.73) < 0.1) {
        echo "--> ¡COINCIDE EXACTAMENTE CON LA IMAGEN ($242.73)!\n";
        echo "Esto confirma que un campo 'oculto' es el responsable de bajar el saldo.\n";
    }
}
