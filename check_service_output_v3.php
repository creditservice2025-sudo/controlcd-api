<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$service = app(App\Services\Collection\CollectionClientService::class);

$client = DB::connection('collection_pgsql')->table('collection_clients')->first();
if (!$client) {
    echo "NO CLIENTS IN DB!\n";
    exit;
}

echo "First Client Name: {$client->name} CompanyID: {$client->company_id}\n";

$response = $service->list(['company_id' => $client->company_id]);
$content = $response->getContent();
$data = json_decode($content, true);

$first = $data['data']['data'][0] ?? null;
if ($first) {
    echo "Service Return Name: " . ($first['name'] ?? 'N/A') . "\n";
    echo "Profile Photo Value: " . ($first['profile_photo'] ?? 'NULL') . "\n";
    echo "Document Photo Value: " . ($first['document_photo'] ?? 'NULL') . "\n";
} else {
    echo "No clients found in service list response for company {$client->company_id}.\n";
}
