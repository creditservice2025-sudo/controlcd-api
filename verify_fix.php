<?php

use App\Services\PaymentService;
use App\Models\Credit;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = 2233;
echo "Manually triggering redistribution for Credit #$id...\n";

try {
    $ps = app(PaymentService::class);
    $resp = $ps->reapplyPayments($id);
    
    echo "Redistribution finished.\n";
    
    // Check results
    $c = Credit::find($id);
    echo "Credit Remaining Amount: " . $c->remaining_amount . "\n";
    
    $paidCount = \App\Models\Installment::where('credit_id', $id)->where('status', 'Pagado')->count();
    $totalCount = \App\Models\Installment::where('credit_id', $id)->count();
    echo "Paid Installments: $paidCount / $totalCount\n";
    
    $unapplied = \App\Models\Payment::where('credit_id', $id)->sum('unapplied_amount');
    echo "Total Unapplied Money: $unapplied\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
