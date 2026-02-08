<?php

use App\Services\CreditService;
use App\Models\Credit;
use Carbon\Carbon;

require __DIR__ . '/bootstrap/app.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$creditId = 6753;
$credit = Credit::find($creditId);
echo "Credit ID: $creditId\n";
echo "Excluded Days: " . $credit->excluded_days . "\n";
echo "Start Date: 2026-01-30\n";
echo "Proposed First Quota (Start + 1 Day): 2026-01-31\n";

$service = new CreditService();

// Simulate passing 2026-01-31 as input date (which logic treats as startDate)
$resp = $service->simulateScheduleChange(
    $creditId,
    '2026-01-31', // newDate
    'schedule', // type
    null, // newFrequency
    null, // newInstallments
    null, // newInterestRate
    null, // newInsurancePercentage
    '2026-01-30', // newStartDate (Used for context or saving?)
    null, // newCreditValue
    true // recalculatePaid
);

if ($resp instanceof \Illuminate\Http\JsonResponse) {
    $data = $resp->getData(true);
} else {
    $data = $resp;
}

// Inspect the first few predicted dates
if (isset($data['success']) && $data['success']) {
    echo "Simulation Success.\n";
    $dates = $data['data']['dates'] ?? [];
    echo "First 5 Dates:\n";
    $count = 0;
    foreach ($dates as $quota => $date) {
        if ($count++ >= 5) break; 
        echo "Quota $quota: $date (" . Carbon::parse($date)->format('l') . ")\n";
    }
} else {
    echo "Simulation Failed: " . json_encode($data) . "\n";
}
