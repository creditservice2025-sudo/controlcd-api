<?php

use App\Models\Credit;
use App\Services\CreditService;
use Carbon\Carbon;

// Find or create a suitable credit
$credit = Credit::first();
if (!$credit) {
    echo "No matching credit found to test.\n";
    exit;
}

// Setup test conditions
$credit->excluded_days = json_encode(["Domingo"]);
$credit->payment_frequency = 'Diaria';
$credit->number_installments = 5;
$credit->save();

echo "Testing Credit ID: " . $credit->id . "\n";
echo "Excluded Days: " . $credit->excluded_days . "\n";
echo "First Quota Input: 2026-01-30\n";

$service = app(CreditService::class);
try {
    $result = $service->simulateScheduleChange(
        $credit->id,
        '2026-01-30', // New First Quota
        'schedule',
        null, null, null, null, null, null,
        true // FORCE Recalculate all installments
    );

    // $data = $result->getData(true);
    $content = $result->getContent();
    $data = json_decode($content, true);
    if (!$data['success']) {
        echo "Simulation failed: " . $data['message'] . "\n";
        exit;
    }

    $installments = $data['data']['installments'];
    foreach ($installments as $inst) {
        $date = Carbon::parse($inst['simulated_date']);
        $dayName = $date->locale('es')->dayName;
        echo "Quota " . $inst['quota_number'] . ": " . $inst['simulated_date'] . " (" . $dayName . ")\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
