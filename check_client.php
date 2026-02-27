<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\CreditController;
use Illuminate\Http\Request;

$controller = app(CreditController::class);
$request = Request::create('/api/credits/client/7431', 'GET');
$response = $controller->getCredits($request, 7431);

echo $response->getContent();
