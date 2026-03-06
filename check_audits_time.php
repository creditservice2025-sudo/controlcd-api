<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$startTime = '2026-02-18 18:30:00';
$endTime = '2026-02-18 18:45:00';

echo "Audits between $startTime and $endTime:\n";
$audits = DB::table('liquidation_audits')
    ->whereBetween('created_at', [$startTime, $endTime])
    ->get();

foreach($audits as $a) {
    echo "ID: {$a->id} | User: {$a->user_id} | Action: {$a->action} | Created: {$a->created_at}\n";
    echo "  Changes: {$a->changes}\n";
}

if($audits->isEmpty()) {
    echo "No audits found in this time range.\n";
}
