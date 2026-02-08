<?php

use App\Models\Credit;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 708;
$credit = Credit::find($creditId);

if (!$credit) {
    echo "Credit #{$creditId} not found.\n";
    exit;
}

echo "Current Status: {$credit->status}\n";
echo "Current Remaining Amount: {$credit->remaining_amount}\n";

$credit->status = 'Liquidado';
$credit->remaining_amount = 0;
$credit->save();

echo "Updated Credit #{$creditId}:\n";
echo "New Status: {$credit->status}\n";
echo "New Remaining Amount: {$credit->remaining_amount}\n";
echo "Credit corrected successfully.\n";
