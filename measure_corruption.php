<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$credits = \App\Models\Credit::where('status', '!=', 'Liquidado')
    ->where('remaining_amount', '<=', 0.01)
    ->with(['installments', 'payments'])
    ->get();

$truePrecisionErrors = 0;
$corruptedData = 0;

echo "Analyzing " . $credits->count() . " credits...\n\n";

foreach ($credits as $c) {
    $totalExpected = $c->installments->sum('quota_amount');
    $totalPaid = $c->payments->sum('amount');
    $realDebt = round($totalExpected - $totalPaid, 2);

    if ($realDebt <= 0.50) { // arbitrary threshold, e.g. 50 cents max for precision
        $truePrecisionErrors++;
    } else {
        $corruptedData++;
        if ($corruptedData <= 10) {
            echo "CORRUPTED (Fake Remaining): ID {$c->id} | Expected: {$totalExpected} | Paid: {$totalPaid} | Real Debt: {$realDebt} | DB Remaining: {$c->remaining_amount}\n";
        }
    }
}

echo "\n--- SUMMARY ---\n";
echo "True Precision Errors (<= $0.50 diff): {$truePrecisionErrors}\n";
echo "Corrupted Remaining Amounts (Real debt exists): {$corruptedData}\n";
