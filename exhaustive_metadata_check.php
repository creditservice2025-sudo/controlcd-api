<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$clients = DB::connection('collection_pgsql')->table('collection_clients')->get();
foreach ($clients as $c) {
    $meta = json_decode($c->metadata, true);
    if ($meta) {
        echo "Client: {$c->name}\n";
        echo "Keys: " . implode(", ", array_keys($meta)) . "\n";
        foreach ($meta as $k => $v) {
            echo "  {$k}: " . ($v ? substr($v, 0, 30) . "..." : 'NULL') . "\n";
        }
        echo "------------------\n";
    }
}
