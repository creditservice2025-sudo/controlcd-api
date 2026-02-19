<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$columns = DB::select("SHOW COLUMNS FROM expenses");
echo "Table Structure for expenses:\n";
foreach($columns as $c) {
    echo "Field: {$c->Field} | Type: {$c->Type} | Null: {$c->Null} | Key: {$c->Key} | Default: {$c->Default}\n";
}
