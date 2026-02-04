<?php

use Illuminate\Contracts\Console\Kernel;
use App\Models\Credit;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

ini_set('memory_limit', '512M');

$results = [];
Credit::with(['payments', 'installments', 'client'])
    ->whereIn('status', ['Vigente', 'Atrasado'])
    ->chunk(50, function ($credits) use (&$results) {
        foreach ($credits as $credit) {
            $unapplied = $credit->payments
                ->where('status', '!=', 'Anulado')
                ->sum('unapplied_amount');
                
            if ($unapplied <= 0.01) continue;
            
            $next = $credit->installments
                ->where('status', '!=', 'Pagado')
                ->sortBy('due_date')
                ->first();
                
            if (!$next) continue;
            
            $pending = $next->quota_amount - $next->paid_amount;
            
            if ($unapplied >= ($pending - 0.01)) {
                $results[] = [
                    'id' => $credit->id,
                    'client' => $credit->client->name ?? 'Unknown',
                    'u' => $unapplied,
                    'p' => $pending,
                    'q' => $next->quota_number
                ];
            }
        }
    });

echo json_encode($results, JSON_PRETTY_PRINT);
