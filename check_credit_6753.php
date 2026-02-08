<?php
use App\Models\Credit;

require __DIR__ . '/bootstrap/app.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 6753;
$credit = Credit::find($creditId);

if (!$credit) {
    echo "Credit $creditId not found.\n";
    exit;
}

echo "Credit ID: " . $credit->id . "\n";
echo "Excluded Days (Raw): " . $credit->excluded_days . "\n";

$decoded = json_decode($credit->excluded_days, true);
echo "Excluded Days (Decoded): " . print_r($decoded, true) . "\n";
