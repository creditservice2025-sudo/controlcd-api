<?php

use App\Models\Client;
use App\Models\Seller;
use App\Models\Credit;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "--- Buscando Vendedor 'Alejandra' ---\n";
$users = \App\Models\User::where('name', 'LIKE', '%Alejandra%')->get();

if ($users->isEmpty()) {
    echo "No se encontraron USUARIOS con nombre 'Alejandra'.\n";
} else {
    foreach ($users as $user) {
        $seller = Seller::where('user_id', $user->id)->first();
        if ($seller) {
            echo "User: {$user->name} (ID: {$user->id}) -> Seller ID: {$seller->id}, Status: {$seller->status}\n";
        } else {
            echo "User: {$user->name} (ID: {$user->id}) -> NO es vendedor.\n";
        }
    }
}

echo "\n--- Buscando Cliente 'WU' ---\n";
$clients = Client::where('name', 'LIKE', '%WU%')->get();

if ($clients->isEmpty()) {
    echo "No se encontraron clientes con nombre 'WU'.\n";
} else {
    foreach ($clients as $client) {
        echo "Client ID: {$client->id}, Name: {$client->name}, Seller ID: {$client->seller_id}, Status: {$client->status}\n";
        
        $credits = $client->credits;
        if ($credits->isEmpty()) {
            echo "  [!] Este cliente NO tiene créditos.\n";
        } else {
            foreach ($credits as $credit) {
                echo "  Credit ID: {$credit->id}, Status: {$credit->status}, Start: {$credit->start_date}\n";
                echo "  Installments Count: " . $credit->installments()->count() . "\n";
                
                $firstInst = $credit->installments()->orderBy('due_date', 'asc')->first();
                $lastInst = $credit->installments()->orderBy('due_date', 'desc')->first();
                
                if ($firstInst) {
                    echo "    First Payment Date (due_date): {$firstInst->due_date} (Status: {$firstInst->status})\n";
                }
                if ($lastInst) {
                    echo "    Last Payment Date (due_date): {$lastInst->due_date}\n";
                }
                
                // Ver si hay cuotas para HOY (2026-02-04)
                $today = '2026-02-04';
                $todayInst = $credit->installments()->whereDate('due_date', $today)->first();
                if ($todayInst) {
                    echo "    [OK] Cuota programada para HOY ($today): #{$todayInst->quota_number} - {$todayInst->status}\n";
                } else {
                    echo "    [WARN] NO hay cuota programada para HOY ($today).\n";
                    // Buscar la próxima
                    $next = $credit->installments()->where('due_date', '>', $today)->orderBy('due_date')->first();
                    if ($next) echo "    Próxima cuota: {$next->due_date}\n";
                }
            }
        }
    }
}
