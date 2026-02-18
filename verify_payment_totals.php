<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PaymentController;
use Illuminate\Http\Request;
use App\Models\User;

$user = User::find(60); // El vendedor
auth()->login($user);

$controller = app(PaymentController::class);
$request = new Request(['date' => '2026-02-18', 'timezone' => 'America/Bogota']);

echo "Verificando /api/payments/daily-totals para el 2026-02-18...\n";
$response = $controller->dailyPaymentTotals($request);
$data = json_decode($response->getContent(), true);

echo "RESPONSE DATA:\n";
echo json_encode($data, JSON_PRETTY_PRINT) . "\n";

if (($data['data']['total_income'] ?? null) == 0) {
    echo "\nSUCCESS: El total de ingresos para el 18 es 0.\n";
} else {
    echo "\nFAILURE: El total de ingresos para el 18 sigue siendo " . ($data['data']['total_income'] ?? 'N/A') . "\n";
}

$request17 = new Request(['date' => '2026-02-17', 'timezone' => 'America/Bogota']);
$response17 = $controller->dailyPaymentTotals($request17);
$data17 = json_decode($response17->getContent(), true);
echo "2026-02-17 Total Income: " . ($data17['data']['total_income'] ?? 'N/A') . "\n";
