<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\ClientService;
use Illuminate\Support\Facades\Auth;

try {
    $user = User::where('role_id', 1)->first();
    if (!$user) {
        die("No super admin user found\n");
    }
    Auth::login($user);
    $service = app(ClientService::class);
    $res = $service->getClientsBySeller(101);
    echo "Success: Found " . count($res) . " clients\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
