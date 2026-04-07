<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$service = app(App\Services\Collection\CollectionClientService::class);
$response = $service->list(['company_id' => 1]);
$data = $response->getData();

$first = $data->data->data[0] ?? null;
if ($first) {
    echo "First Client Name: {$first->name}\n";
    echo "Profile Photo: " . ($first->profile_photo ?? 'MISSING') . "\n";
    echo "Document Photo: " . ($first->document_photo ?? 'MISSING') . "\n";
} else {
    echo "No clients found in service list response.\n";
}
