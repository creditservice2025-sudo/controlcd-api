<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Holiday;

$commonHolidays = Holiday::whereNull('country_id')->get();
foreach ($commonHolidays as $h) {
    $dup = Holiday::whereNotNull('country_id')
        ->where('description', $h->description)
        ->where('date', $h->date)
        ->first();
    if ($dup) {
        echo "Overlap: {$h->description} on {$h->date} (ID {$h->id} null, ID {$dup->id} country {$dup->country_id})\n";
    }
}
