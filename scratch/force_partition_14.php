<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

echo "--- Forcing Partition Creation (Company 14) ---\n";

$tables = [
    'collection_clients',
    'collection_credits', 
    'collection_installments',
    'collection_payments'
];

foreach ($tables as $table) {
    echo "Processing $table... ";
    try {
        $partitionTable = "{$table}_company_14";
        DB::connection('collection_pgsql')->statement(
            "CREATE TABLE IF NOT EXISTS $partitionTable PARTITION OF $table FOR VALUES IN (14)"
        );
        echo "OK\n";
    } catch (\Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
