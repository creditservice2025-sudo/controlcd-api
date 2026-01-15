<?php

use App\Services\PaymentService;
use App\Services\GeolocationHistoryService;
use Illuminate\Http\Request;

$service = new PaymentService(new GeolocationHistoryService());
$request = new Request();
// Enable status 'Abonado' or 'Pagado' if needed, but index usually returns all unless filtered.
// SystemAdjustment calls: api.get(`/payments/${selectedCredit.value}?perPage=100`)

// Mock request
$request->merge(['perPage' => 100]);

echo "Calling PaymentService::index for Credit 2941...\n";

try {
    $response = $service->index(2941, $request, 100);
    $content = $response->getContent();
    $data = json_decode($content, true);
    
    if ($data['success']) {
        $payments = $data['data']['data'];
        echo "Found " . count($payments) . " payments.\n";
        foreach ($payments as $p) {
            echo "Payment ID: " . $p['id'] . "\n";
            echo "  Amount: " . $p['total_payment'] . "\n"; // mapped from total_payment
            echo "  Unapplied: " . ($p['unapplied_amount'] ?? 'NULL') . "\n";
            echo "  Raw Amount: " . $p['amount'] . "\n"; // mapped from amount
        }
    } else {
        echo "Service returned success=false: " . $data['message'] . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
