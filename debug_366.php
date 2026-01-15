<?php
// debug_366.php

use App\Models\Credit;
use Carbon\Carbon;

$sellerId = 19;
$date = '2025-12-03';
$timezone = 'America/Lima';

$startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
$endUTC = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

echo "Checking credits for Seller $sellerId between $startUTC and $endUTC (UTC)\n";

$credits = Credit::where('seller_id', $sellerId)
    ->whereBetween('created_at', [$startUTC, $endUTC])
    ->get();

foreach ($credits as $c) {
    echo "ID: {$c->id} | Value: {$c->credit_value} | % Policy: {$c->micro_insurance_percentage} | Calc Policy: ". ($c->credit_value * $c->micro_insurance_percentage / 100) . "\n";
}
exit();
