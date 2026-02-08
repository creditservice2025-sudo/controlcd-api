<?php

$service = app(\App\Services\CreditService::class);

$credit = \App\Models\Credit::find(4531); 
// ...
$newStart = \Carbon\Carbon::parse('2026-01-23'); // From Log "new_start_date":"2026-0 [truncated]" likely 01-23 as per previous context
$newFirstQuota = \Carbon\Carbon::parse('2026-02-07'); // From Log "NewDate: 2026-02-07"

echo "Simulating Change with RecalcPaid=TRUE and Date=07/02:\n";
try {
    $resp = $service->simulateScheduleChange(
        $credit->id,
        $newFirstQuota->format('Y-m-d'), 
        'schedule',
        null, 
        null, null, null,
        $newStart->format('Y-m-d'),
        null,
        true 
    );
    // Print logic for TRUE...
    if ($resp instanceof \Illuminate\Http\JsonResponse) { $data = $resp->getData(true); } else { $data = json_decode($resp->getContent(), true); }
    if (isset($data['data']['installments'])) {
        foreach (array_slice($data['data']['installments'], 0, 5) as $inst) {
            echo "Q" . $inst['quota_number'] . ": " . $inst['simulated_date'] . "\n";
        }
    }
} catch (\Exception $e) { echo "Error True: " . $e->getMessage() . "\n"; }

echo "\n--- Test with RECALCULATE_PAID = FALSE (Default) ---\n";
try {
    $resp = $service->simulateScheduleChange(
        $credit->id,
        $newFirstQuota->format('Y-m-d'), 
        'schedule',
        null, 
        null, null, null,
        $newStart->format('Y-m-d'),
        null,
        false 
    );
    // Print logic for FALSE...
    if ($resp instanceof \Illuminate\Http\JsonResponse) { $data = $resp->getData(true); } else { $data = json_decode($resp->getContent(), true); }
    if (isset($data['data']['installments'])) {
        foreach (array_slice($data['data']['installments'], 0, 5) as $inst) {
            echo "Q" . $inst['quota_number'] . ": " . $inst['simulated_date'] . "\n";
        }
    }
} catch (\Exception $e) { echo "Error False: " . $e->getMessage() . "\n"; }
