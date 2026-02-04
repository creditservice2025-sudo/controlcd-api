<?php

use App\Models\Credit;
use App\Models\Payment;
use App\Models\Installment;
use Illuminate\Support\Facades\DB;

function runDiagnosis() {
    echo "Starting Payment-Installment Mismatch Diagnosis...\n";

    $credits = Credit::with(['payments', 'installments'])
        ->whereIn('status', ['Vigente', 'Atrasado'])
        ->get();

    $affectedCredits = [];

    foreach ($credits as $credit) {
        $totalUnapplied = $credit->payments->where('status', '!=', 'Anulado')->sum('unapplied_amount');
        
        if ($totalUnapplied <= 0) continue;

        $nextInstallment = $credit->installments
            ->where('status', '!=', 'Pagado')
            ->sortBy('due_date')
            ->first();

        if (!$nextInstallment) continue;

        $pendingAmount = $nextInstallment->quota_amount - $nextInstallment->paid_amount;

        if ($totalUnapplied >= ($pendingAmount - 0.01)) {
            $affectedCredits[] = [
                'credit_id' => $credit->id,
                'credit_number' => $credit->credit_number ?? $credit->id,
                'client' => $credit->client->name ?? 'Unknown',
                'unapplied_amount' => $totalUnapplied,
                'installment_pending' => $pendingAmount,
                'installment_number' => $nextInstallment->quota_number
            ];
        }
    }

    echo "Report:\n";
    echo "Total credits checked: " . $credits->count() . "\n";
    echo "Credits with mismatch: " . count($affectedCredits) . "\n\n";

    if (!empty($affectedCredits)) {
        printf("%-10s | %-20s | %-12s | %-12s | %-5s\n", "ID", "Client", "Unapplied", "Inst. Pend", "Inst#");
        echo str_repeat("-", 70) . "\n";
        foreach ($affectedCredits as $item) {
            printf("%-10s | %-20s | %-12.2f | %-12.2f | %-5d\n", 
                $item['credit_id'], 
                substr($item['client'], 0, 20), 
                $item['unapplied_amount'], 
                $item['installment_pending'],
                $item['installment_number']
            );
        }
    }
}

// In a real Laravel environment, we can run this via artisan tinker or a temporary route.
// For now, I'll provide the logic and ask the user if they want me to run it as a command.
// I can also just use the Tinker shell if I can.
