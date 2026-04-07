<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$paula = DB::connection('collection_pgsql')->table('collection_clients')->where('name', 'ilike', '%paula%')->first();
if ($paula) {
    echo "Paula Metadata: {$paula->metadata}\n";
} else {
    echo "Paula not found!\n";
}
