<?php
$creditId = 6753;
$credit = \App\Models\Credit::find($creditId);
echo "Excluded Days: " . $credit->excluded_days . "\n";
$service = app(\App\Services\CreditService::class);
try {
    $resp = $service->simulateScheduleChange($creditId, '2026-01-31', 'schedule', null, null, null, null, '2026-01-30', null, true);
    
    if ($resp instanceof \Illuminate\Http\JsonResponse) {
        $data = $resp->getData(true);
    } elseif (is_array($resp)) {
        $data = $resp;
    } else {
        echo "Response is type: " . get_class($resp) . "\n";
        // Try to get content
        $content = $resp->getContent();
        $data = json_decode($content, true);
    }
    
    if (isset($data['data']['installments'])) {
        $dates = array_map(function($i) { return $i['simulated_date']; }, $data['data']['installments']);
        echo "Installments Dates: " . json_encode(array_slice($dates, 0, 5)) . "\n";
    } else {
        echo "No installments found. Data content: " . json_encode($data['data'] ?? 'NULL') . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getFile() . ":" . $e->getLine() . " - " . $e->getMessage() . "\n";
}
