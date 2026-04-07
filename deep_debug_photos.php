<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "--- COLUMNS --- \n";
$cols = Schema::connection('collection_pgsql')->getColumnListing('collection_clients');
print_r($cols);

echo "--- METADATA VALUE --- \n";
if (in_array('metadata', $cols)) {
    $clients = DB::connection('collection_pgsql')->table('collection_clients')->select('id', 'name', 'metadata')->get();
    foreach ($clients as $c) {
        echo "ID: {$c->id} Name: {$c->name}\n";
        echo "Metadata: {$c->metadata}\n";
        echo "------------------\n";
    }
} else {
    echo "NO METADATA COLUMN FOUND!\n";
}
