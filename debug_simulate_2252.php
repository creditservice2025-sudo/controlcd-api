<?php

use App\Models\Credit;
use App\Services\CreditService;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 2252;
$service = app(CreditService::class);

echo "Testing simulation for Credit ID: $creditId\n";

try {
    $result = $service->simulateDelete($creditId);
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (\Exception $e) {
    echo "CAUGHT EXCEPTION: " . $e->getMessage() . "\n";
    echo "TRACE: " . $e->getTraceAsString() . "\n";
}
