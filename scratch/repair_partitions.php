<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use App\Services\Collection\CollectionPartitionService;
use App\Models\Company;

echo "--- Repairing PostgreSQL Partitions ---\n";

try {
    $service = app(CollectionPartitionService::class);
    $companyIds = Company::pluck('id');

    foreach ($companyIds as $id) {
        $service->ensurePartitions($id);
        echo "Check complete for Company ID: $id\n";
    }

    echo "Finished repairing all partitions.\n";
} catch (\Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}
