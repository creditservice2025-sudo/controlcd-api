<?php

use App\Models\Credit;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$batchSize = 100;
$query = Credit::whereNotIn('status', ['Liquidado', 'Anulado', 'Rechazado']);
$count = $query->count();

echo "Scanning {$count} active credits...\n";

$inconsistentOptions = [];

$query->chunk($batchSize, function ($credits) use (&$inconsistentOptions) {
    foreach ($credits as $credit) {
        // Calculate real pending amount
        try {
            $calculatedPending = $credit->pendingAmount();
            
            // Check inconsistency: Calculated is 0 (paid off) but status is NOT Liquidado
            if ($calculatedPending < 0.01) {
                // Determine if it's truly inconsistent
                // Sometimes credits are 0 but waiting for a final closure trigger? 
                // In this system, if it's 0 it should be Liquidado.
                
                $inconsistentOptions[] = [
                    'id' => $credit->id,
                    'client' => $credit->client ? ($credit->client->name . ' ' . $credit->client->last_name) : 'Unknown',
                    'status' => $credit->status,
                    'db_remaining_amount' => $credit->remaining_amount,
                    'calculated_pending' => $calculatedPending,
                    'issue' => "Calculated balance is 0 but status is '{$credit->status}'"
                ];
                continue;
            }

            // Check secondary inconsistency: DB column says 0 but status is active
            // (Only if our calculatedPending check didn't already catch it)
            if ($credit->remaining_amount < 0.01 && $calculatedPending > 0.01) {
                 // This is the opposite case: DB says paid, but calculation says pending.
                 // This is also bad, but less likely to be the user's specific complaint.
                 // We will verify this too.
                 $inconsistentOptions[] = [
                    'id' => $credit->id,
                    'client' => $credit->client ? ($credit->client->name . ' ' . $credit->client->last_name) : 'Unknown',
                    'status' => $credit->status,
                    'db_remaining_amount' => $credit->remaining_amount,
                    'calculated_pending' => $calculatedPending,
                    'issue' => "DB remaining_amount is 0 but calculated pending is {$calculatedPending}"
                ];
            }
            
            // Usage of epsilon for float comparison
            if (abs($credit->remaining_amount - $calculatedPending) > 0.05) {
                 $inconsistentOptions[] = [
                    'id' => $credit->id,
                    'client' => $credit->client ? ($credit->client->name . ' ' . $credit->client->last_name) : 'Unknown',
                    'status' => $credit->status,
                    'db_remaining_amount' => $credit->remaining_amount,
                    'calculated_pending' => $calculatedPending,
                    'issue' => "Mismatch: DB says {$credit->remaining_amount}, Calc says {$calculatedPending}"
                ];
            }

        } catch (\Exception $e) {
            echo "Error processing Credit {$credit->id}: " . $e->getMessage() . "\n";
        }
    }
});

echo "\nFound " . count($inconsistentOptions) . " potentially inconsistent credits.\n\n";

if (count($inconsistentOptions) > 0) {
    echo str_pad("ID", 8) . " | " . str_pad("Status", 10) . " | " . str_pad("DB Rem.", 10) . " | " . str_pad("Calc Rem.", 10) . " | " . "Issue\n";
    echo str_repeat("-", 80) . "\n";
    foreach ($inconsistentOptions as $opt) {
        echo str_pad($opt['id'], 8) . " | " . 
             str_pad(substr($opt['status'], 0, 10), 10) . " | " . 
             str_pad($opt['db_remaining_amount'], 10) . " | " . 
             str_pad(number_format($opt['calculated_pending'], 2), 10) . " | " . 
             $opt['issue'] . "\n";
    }
}
