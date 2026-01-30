<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Liquidation;

$liquidations = Liquidation::whereHas('seller.user', function($q){ 
        $q->where('name', 'like', '%Mono%'); 
    })->orderBy('date', 'desc')->get();

foreach($liquidations as $l) {
    echo "DATE: " . $l->date->toDateString() . " | ID: " . $l->id . " | PATH: " . $l->path . " | CAPTURE_PATH: " . $l->capture_path . "\n";
}
