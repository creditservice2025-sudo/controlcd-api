<?php

use App\Models\Client;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\Client\ClientRequest;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "--- Verifying DNI Validation Fix ---\n";

// Find a conflicting case from my previous diagnostic
// DNI: 1059903304 in seller 45
// Active ID: 10860, Deleted ID: 10770

$dni = '1059903304';
$sellerId = 45;
$clientId = 10860;

echo "Simulating update for Client ID: $clientId, DNI: $dni, Seller: $sellerId\n";

// Manually build the rules exactly as they are in ClientRequest.php now
$rules = [
    'dni' => "nullable|numeric|unique:clients,dni,$clientId,id,seller_id,$sellerId,deleted_at,NULL"
];

$data = [
    'dni' => $dni,
    'seller_id' => $sellerId
];

$validator = Validator::make($data, $rules);

if ($validator->fails()) {
    echo "FAILED: Validation still flags the DNI as duplicate.\n";
    print_r($validator->errors()->all());
} else {
    echo "SUCCESS: Validation passed (correctly ignored soft-deleted record).\n";
}

echo "\n--- Verifying manual check in ClientService ---\n";
$existingClient = Client::where('dni', $dni)
    ->where('seller_id', $sellerId)
    ->where('id', '!=', $clientId)
    ->whereNull('deleted_at')
    ->first();

if ($existingClient) {
    echo "FAILED: Manual check still found a conflict.\n";
} else {
    echo "SUCCESS: Manual check correctly ignored the soft-deleted record.\n";
}
