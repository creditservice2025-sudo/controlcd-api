<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "\n=== INVESTIGACIÓN MONO9 (Seller ID: 19) - Fecha 2026-02-06 ===\n\n";

// Obtener todas las liquidaciones de Mono9 para esa fecha (incluyendo eliminadas)
$liquidations = DB::table('liquidations')
    ->where('seller_id', 19)
    ->where('date', 'like', '2026-02-06%')
    ->orderBy('id', 'asc')
    ->get();

echo "Total de liquidaciones encontradas: " . count($liquidations) . "\n\n";

foreach ($liquidations as $liq) {
    echo "--- Liquidación ID: {$liq->id} ---\n";
    echo "  Status: {$liq->status}\n";
    echo "  Created at: {$liq->created_at}\n";
    echo "  Updated at: {$liq->updated_at}\n";
    echo "  Deleted at: " . ($liq->deleted_at ?? 'NULL (ACTIVA)') . "\n";
    echo "  End date: " . ($liq->end_date ?? 'NULL') . "\n";
    echo "\n";
}

// Obtener auditorías de estas liquidaciones
echo "\n=== AUDITORÍAS DE LAS LIQUIDACIONES ===\n\n";

$liquidationIds = $liquidations->pluck('id')->toArray();

$audits = DB::table('liquidation_audits')
    ->whereIn('liquidation_id', $liquidationIds)
    ->orderBy('created_at', 'asc')
    ->get();

foreach ($audits as $audit) {
    $user = DB::table('users')->where('id', $audit->user_id)->first();
    $userName = $user ? $user->name : 'Desconocido';
    $userRole = $user ? $user->role_id : 'N/A';
    
    echo "Liquidación ID: {$audit->liquidation_id}\n";
    echo "  Acción: {$audit->action}\n";
    echo "  Usuario: {$userName} (ID: {$audit->user_id}, Role: {$userRole})\n";
    echo "  Fecha: {$audit->created_at}\n";
    echo "\n";
}

// Verificar si hay registros de reapertura
echo "\n=== BÚSQUEDA DE REAPERTURAS ===\n\n";

$reopenLogs = DB::table('liquidation_audits')
    ->whereIn('liquidation_id', $liquidationIds)
    ->where('action', 'like', '%reopen%')
    ->orWhere('action', 'like', '%reabrir%')
    ->get();

if ($reopenLogs->isEmpty()) {
    echo "No se encontraron registros explícitos de reapertura en auditorías.\n";
} else {
    foreach ($reopenLogs as $log) {
        echo "Registro de reapertura encontrado:\n";
        print_r($log);
    }
}
