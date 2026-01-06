<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Simular Usuario con Rol Admin para permisos
$user = \App\Models\User::where('role_id', 1)->first(); 
Auth::login($user);

$sellerId = 16; 
$date = date('Y-m-d'); 
// $date = '2025-12-31';

echo "Debugging Controller Method for Seller: $sellerId on Date: $date\n";

$request = Request::create('/', 'GET', [
    'timezone' => 'America/Lima'
]);

$controller = app(\App\Http\Controllers\LiquidationController::class);

try {
    // getLiquidationData es public? Sí, en el código que vi.
    $data = $controller->getLiquidationData($sellerId, $date, $request);
    
    // Si retorna response json, convertir. Si retorna array, usar directo.
    if ($data instanceof \Illuminate\Http\JsonResponse) {
       $json = $data->getData(true);
       echo "\n--- JSON Response ---\n";
       print_r($json);
    } elseif (is_array($data)) {
       echo "\n--- Array Response ---\n";
       print_r($data);
    } else {
       echo "\n--- Unknown Response Type: " . get_class($data) . " ---\n";
       print_r($data);
    }

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
