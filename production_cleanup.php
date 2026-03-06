<?php

/**
 * SCRIPT DE LIMPIEZA DE LIQUIDACIONES DUPLICADAS
 * Este script identifica grupos de (vendedor_id, fecha) que tienen más de un registro
 * y mantiene únicamente el de ID más alto (el más reciente).
 */

use App\Models\Liquidation;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Iniciando limpieza de liquidaciones duplicadas ---\n";

// 1. Identificar grupos duplicados
$duplicates = DB::table('liquidations')
    ->select('seller_id', 'date', DB::raw('COUNT(*) as count'))
    ->groupBy('seller_id', 'date')
    ->having('count', '>', 1)
    ->get();

echo "Se encontraron " . $duplicates->count() . " grupos de duplicados.\n";

$totalDeleted = 0;

foreach ($duplicates as $dup) {
    echo "Procesando vendedor {$dup->seller_id} fecha {$dup->date} ({$dup->count} registros)...\n";
    
    // 2. Buscar el ID más alto (el más reciente) para mantenerlo
    $keepId = Liquidation::withTrashed()
        ->where('seller_id', $dup->seller_id)
        ->where('date', $dup->date)
        ->orderBy('id', 'desc')
        ->value('id');
        
    // 3. Eliminar permanentemente los demás del mismo grupo
    $deleted = Liquidation::withTrashed()
        ->where('seller_id', $dup->seller_id)
        ->where('date', $dup->date)
        ->where('id', '!=', $keepId)
        ->forceDelete();
        
    $totalDeleted += $deleted;
    echo "Eliminados $deleted registros. Manteniendo ID $keepId.\n";
}

echo "--- Limpieza finalizada. Total eliminados: $totalDeleted ---\n";
echo "AHORA PUEDES EJECUTAR: php artisan migrate\n";
