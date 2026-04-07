<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$service = app(App\Services\Collection\CollectionClientService::class);
// Get the raw result by bypassing the successResponse wrapper if possible or just decoding the JSON
$response = $service->list(['company_id' => 1]);
$content = $response->getContent();
$data = json_decode($content, true);

$first = $data['data']['data'][0] ?? null;
if ($first) {
    echo "First Client Name: " . ($first['name'] ?? 'N/A') . "\n";
    echo "Profile Photo Key: " . (array_key_exists('profile_photo', $first) ? 'EXISTS' : 'MISSING') . "\n";
    echo "Profile Photo Value: " . ($first['profile_photo'] ?? 'NULL') . "\n";
    echo "Document Photo Value: " . ($first['document_photo'] ?? 'NULL') . "\n";
} else {
    echo "No clients found in service list response.\n";
}
