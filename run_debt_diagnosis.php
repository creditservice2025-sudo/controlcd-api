<?php

use Illuminate\Contracts\Console\Kernel;
use App\Models\Credit;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

ini_set('memory_limit', '512M');

$results = [];

// Get all credits and their totals
$creditsInfo = DB::table('credits')
    ->join('clients', 'credits.client_id', '=', 'clients.id')
    ->select('credits.id', 'clients.name as client_name', 'credits.status')
    ->get();

foreach ($creditsInfo as $c) {
    // Total Payments
    $totalPayments = DB::table('payments')
        ->where('credit_id', $c->id)
        ->where('status', '!=', 'Anulado')
        ->sum('amount');
        
    // Total Applied in payment_installments
    $totalApplied = DB::table('payment_installments')
        ->join('payments', 'payment_installments.payment_id', '=', 'payments.id')
        ->where('payments.credit_id', $c->id)
        ->where('payments.status', '!=', 'Anulado')
        ->sum('applied_amount');
        
    $diff = round($totalPayments - $totalApplied, 2);
    
    if ($diff > 0.01) {
        $results[] = [
            'id' => $c->id,
            'client' => $c->client_name,
            'status' => $c->status,
            'total_payments' => $totalPayments,
            'total_applied' => $totalApplied,
            'difference' => $diff
        ];
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
