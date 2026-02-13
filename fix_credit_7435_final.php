<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use App\Models\Payment;
use App\Models\Installment;
use App\Models\PaymentInstallment;
use Illuminate\Support\Facades\DB;

function logFinal($msg) {
    echo "[" . date('H:i:s') . "] " . $msg . "\n";
}

$creditId = $argv[1] ?? 7435;

logFinal("=== STARTING FINAL FIX FOR CREDIT $creditId ===");

$credit = Credit::find($creditId);
if (!$credit) {
    die("Credit not found.");
}

DB::beginTransaction();

try {
    // 1. CLEAR HISTORY
    logFinal("1. Clearing History...");
    $paymentIds = $credit->payments->pluck('id');
    PaymentInstallment::whereIn('payment_id', $paymentIds)->delete();
    
    // Reset Installments
    Installment::where('credit_id', $credit->id)->update([
        'paid_amount' => 0, 
        'status' => 'Pendiente'
    ]);
    
    // Reset Payments
    $payments = Payment::where('credit_id', $credit->id)
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'asc')
        ->get();
        
    foreach ($payments as $p) {
        $p->unapplied_amount = $p->amount;
        $p->status = ($p->amount > 0) ? 'Pagado' : 'No pagado';
        $p->save();
    }
    logFinal("   History Cleared. Payments Reset: " . $payments->count());

    // 2. WATERFALL REDISTRIBUTION
    logFinal("2. Distributing Payments...");
    
    // IMPORTANT: Use values() to ensure 0-based indexing for the loop
    $installments = Installment::where('credit_id', $credit->id)
        ->orderBy('quota_number', 'asc')
        ->get()
        ->values(); 
        
    logFinal("   Loaded Installments: " . $installments->count());
        
    $installmentsIndex = 0;
    
    foreach ($payments as $payment) {
        if ($payment->amount <= 0) continue;
        
        $availableMoney = $payment->unapplied_amount;
        logFinal("   Processing Payment ID {$payment->id} (Amount: $availableMoney)");
        
        while ($availableMoney > 0.001 && $installmentsIndex < $installments->count()) {
            $inst = $installments[$installmentsIndex];
            
            // Calculate what is pending for this installment
            $pendingQuota = round($inst->quota_amount - $inst->paid_amount, 2);
            
            if ($pendingQuota <= 0) {
                 // Already data-filled (shouldn't happen directly after reset, but safe to check)
                 $inst->status = 'Pagado';
                 $inst->save();
                 $installmentsIndex++;
                 continue;
            }
            
            $toPay = min($availableMoney, $pendingQuota);
            
            // Apply
            $inst->paid_amount = round($inst->paid_amount + $toPay, 2);
            $availableMoney = round($availableMoney - $toPay, 2);
            
            // Update Status
            if (abs($inst->quota_amount - $inst->paid_amount) < 0.001) {
                $inst->status = 'Pagado';
                $installmentsIndex++; // Move to next installment
            } else {
                $inst->status = 'Parcial';
            }
            $inst->save();
            
            // Create Pivot
            PaymentInstallment::create([
                'payment_id' => $payment->id,
                'installment_id' => $inst->id,
                'applied_amount' => $toPay,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            logFinal("     -> Paid $toPay to Quota #{$inst->quota_number}. (Quota Status: {$inst->status})");
        }
        
        // Save remaining unapplied
        $payment->unapplied_amount = $availableMoney;
        $payment->save();
        
        if ($availableMoney > 0.001) {
            logFinal("     -> WARN: Payment finished with SURPLUS: $availableMoney");
        }
    }
    
    // 3. FINAL CHECKS
    $totalDebt = $installments->sum('quota_amount');
    $totalPaid = $installments->sum('paid_amount');
    $remaining = round($totalDebt - $totalPaid, 2);
    
    logFinal("3. Summary:");
    logFinal("   Total Debt: $totalDebt");
    logFinal("   Total Paid: $totalPaid");
    logFinal("   Remaining: $remaining");
    
    $credit->remaining_amount = $remaining;
    if ($remaining <= 0.01) {
        $credit->status = 'Liquidado';
    } else {
        $credit->status = 'Vigente';
    }
    $credit->save();
    
    DB::commit();
    logFinal("SUCCESS: CHANGES COMMITTED TO DATABASE.");

} catch (\Exception $e) {
    DB::rollBack();
    logFinal("CRITICAL ERROR: " . $e->getMessage());
    logFinal($e->getTraceAsString());
}
