<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\LiquidationController;
use Illuminate\Http\Request;
use App\Models\User;

$user = User::find(60); // El venedor
auth()->login($user);

$controller = app(LiquidationController::class);
$request = new Request(['timezone' => 'America/Bogota']);

$sellerId = 52;
$date = '2026-02-18';

echo "Simulando petición a /api/liquidations/$sellerId/$date\n";
$response = $controller->getLiquidationData($sellerId, $date, $request);

echo "RESPONSE JSON:\n";
echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
