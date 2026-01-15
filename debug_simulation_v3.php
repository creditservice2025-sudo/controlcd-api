<?php

use App\Services\CreditService;
use App\Models\Credit;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$creditId = 2945;
$service = app(CreditService::class);

echo "Simulando eliminación de crédito ID: $creditId\n";
$response = $service->simulateDelete($creditId);
$data = json_decode($response->getContent(), true);

if (!$data) {
    echo "Respuesta no es JSON válido:\n";
    echo $response->getContent() . "\n";
    exit;
}

if (isset($data['success']) && $data['success']) {
    echo "Simulación exitosa.\n";
    $affected = $data['data']['affected_liquidations'];
    echo "Liquidaciones afectadas: " . count($affected) . "\n";
    
    foreach ($affected as $item) {
        $liq = $item['liquidation'];
        $changes = $item['changes'];
        echo "--------------------------------------------------\n";
        echo "Fecha: {$liq['date']}\n";
        echo "Impacto Neto: " . number_format($item['impact_amount'], 2) . "\n";
        
        foreach ($changes as $key => $newVal) {
            $oldVal = $liq[$key] ?? 0;
            $diff = $newVal - $oldVal;
            if ($diff != 0) {
                echo "  [$key]: " . number_format($oldVal, 2) . " -> " . number_format($newVal, 2) . " (Diff: " . number_format($diff, 2) . ")\n";
            }
        }
        
        if (isset($item['breakdown'])) {
            echo "  Desglose (Breakdown):\n";
            foreach ($item['breakdown'] as $b) {
                echo "    - {$b['label']}: " . number_format($b['value'], 2) . "\n";
            }
        }
    }
} else {
    echo "Error en simulación: " . $data['message'] . "\n";
}
