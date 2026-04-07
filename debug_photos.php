<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$clients = DB::connection('collection_pgsql')->table('collection_clients')->select('id', 'name', 'profile_photo', 'document_photo')->get();

foreach ($clients as $client) {
    echo "ID: {$client->id}\n";
    echo "Name: {$client->name}\n";
    echo "Profile Photo: " . ($client->profile_photo ?? 'NULL') . "\n";
    echo "Document Photo: " . ($client->document_photo ?? 'NULL') . "\n";
    echo "--------------------------\n";
}
