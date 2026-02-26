<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Payment;
use App\Models\Installment;
use App\Models\Credit;
use Illuminate\Support\Facades\DB;

$creditId = 12825;
$credit = Credit::find($creditId);
if (!$credit) die("Credit not found!\n");

DB::beginTransaction();
try {
    // Simulate New Payment
    $payment = Payment::create([
        'credit_id' => $creditId,
        'amount' => 100,
        'status' => 'Pagado',
        'payment_date' => date('Y-m-d'),
        'business_timestamp' => date('Y-m-d H:i:s'),
        'business_date' => date('Y-m-d'),
        'business_timezone' => 'America/Caracas'
    ]);

    $payment->unapplied_amount = $payment->amount; // 100
    $payment->save();

    echo "Initial Unapplied: {$payment->unapplied_amount}\n";

    $installments = Installment::where('credit_id', $credit->id)
        ->whereIn('status', ['Pendiente', 'Parcial', 'Atrasado'])
        ->orderBy('due_date')
        ->get();

    echo "Found {$installments->count()} installments.\n";

    foreach ($installments as $idx => $installment) {
        $quotaAmount = (float) $installment->quota_amount;
        $alreadyPaid = (float) $installment->paid_amount;
        $targetAmount = round($quotaAmount - $alreadyPaid, 2);

        echo "Loop [{$idx}]: Quota={$quotaAmount}, Paid={$alreadyPaid}, Target={$targetAmount}\n";

        if ($targetAmount <= 0.001) {
            echo " - Skipping\n";
            continue;
        }

        $payment->refresh();
        echo " - Payment refreshed. Unapplied={$payment->unapplied_amount}\n";

        if ($payment->unapplied_amount >= $targetAmount) {
            echo " - applyPaymentToInstallment called (Direct from new payment)\n";
            // SIMULATE
            $payment->unapplied_amount -= $targetAmount;
            if ($payment->unapplied_amount < 0) {
                $payment->unapplied_amount = 0;
            }
            $payment->save();

            $installment->paid_amount += $targetAmount;
            $installment->save();
            continue;
        }

        echo " - STEP 2 Check\n";
        $stackPayments = Payment::where('credit_id', $credit->id)
            ->where('unapplied_amount', '>', 0)
            ->orderBy('created_at', 'asc') // FIFO
            ->get();

        $totalStack = $stackPayments->sum('unapplied_amount');
        echo " - Total Stack: {$totalStack}\n";

        if ($totalStack >= $targetAmount) {
            $amountNeeded = $targetAmount;
            foreach ($stackPayments as $stackPayment) {
                if ($amountNeeded <= 0) break;
                $available = $stackPayment->unapplied_amount;
                $toTake = min($available, $amountNeeded);
                echo " - Taking {$toTake} from stack payment {$stackPayment->id}\n";
                $amountNeeded -= $toTake;
            }
        } else {
            echo " - Stack not enough. Breaking loop.\n";
            break;
        }
    }

    echo "Final Unapplied: {$payment->unapplied_amount}\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

DB::rollBack();
