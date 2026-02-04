<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Parametros simulados
$search = "ANDRES OLIVOS ABAB";
$todayLocal = date('Y-m-d'); 
$sellerId = 18; 

echo "Searching for '$search' with seller ID $sellerId...\n";

// 1. Verificar si el crédito existe y es visible en query basica
$baseQuery = Credit::whereHas('client', function($q) use ($search) {
    $q->where('name', 'like', "%$search%");
})
->whereNotIn('status', ['Liquidado'])
->get();

echo "Found " . $baseQuery->count() . " basic credits.\n";
foreach($baseQuery as $c) {
    echo " - ID: {$c->id}, Status: {$c->status}, Client: {$c->client->name} (Seller: {$c->client->seller_id})\n";
}

// 2. Verificar filtros complejos de getForCollections
// Copiando logica de fechas de ClientService
$creditsQuery = Credit::query()
    ->select('credits.*')
    ->join('clients', 'clients.id', '=', 'credits.client_id')
    ->where(function ($query) use ($todayLocal) {
        $query->whereNotIn('credits.status', ['Liquidado', 'Unificado', 'Cartera Irrecuperable', 'Renovado'])
            ->orWhere(function ($q) use ($todayLocal) {
                $q->whereIn('credits.status', ['Liquidado', 'Renovado'])
                    ->whereHas('payments', function ($pq) use ($todayLocal) {
                        $pq->where('business_date', $todayLocal);
                    });
            });
    })
    ->where(function ($query) use ($todayLocal) {
        $query->where(function ($q) use ($todayLocal) {
            $q->whereDate('credits.first_quota_date', $todayLocal)
                ->whereDate('credits.created_at', '<=', $todayLocal);
        })
            ->orWhere(function ($q) use ($todayLocal) {
                $q->whereDate('credits.first_quota_date', '<', $todayLocal);
            })
            ->orWhere(function ($q) use ($todayLocal) {
                $q->whereDate('credits.created_at', '<=', $todayLocal)
                    ->whereDate('credits.first_quota_date', '>', $todayLocal);
            });
    });

// Filtro de busqueda
$creditsQuery->whereHas('client', function ($q) use ($search) {
    $q->where('name', 'like', "%{$search}%");
});

$result = $creditsQuery->get();
echo "Found " . $result->count() . " credits with full filters.\n";

if ($result->count() == 0) {
    echo "Debugging exclusion:\n";
    $c = $baseQuery->first();
    if ($c) {
        echo "Credit {$c->id}:\n";
        echo "first_quota_date: {$c->first_quota_date}\n";
        echo "created_at: {$c->created_at}\n";
        echo "Today: $todayLocal\n";
    }
}
