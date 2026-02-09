<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Buscar liquidaciones duplicadas (incluyendo eliminadas lógicamente)
$duplicates = DB::select('
    SELECT 
        seller_id,
        DATE(date) as liquidation_date,
        COUNT(*) as total_records,
        SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) as active_records,
        SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as deleted_records,
        GROUP_CONCAT(id ORDER BY created_at) as liquidation_ids,
        GROUP_CONCAT(status ORDER BY created_at) as statuses
    FROM liquidations
    GROUP BY seller_id, DATE(date)
    HAVING COUNT(*) > 1
    ORDER BY seller_id, liquidation_date
');

echo "\n=== VENDEDORES CON LIQUIDACIONES DUPLICADAS ===\n\n";

if (empty($duplicates)) {
    echo "No se encontraron liquidaciones duplicadas.\n";
} else {
    foreach ($duplicates as $dup) {
        $seller = App\Models\Seller::with('user')->find($dup->seller_id);
        $sellerName = $seller ? $seller->user->name : 'Desconocido';
        
        echo "Vendedor ID: {$dup->seller_id} - {$sellerName}\n";
        echo "  Fecha: {$dup->liquidation_date}\n";
        echo "  Total registros: {$dup->total_records}\n";
        echo "  Activos: {$dup->active_records}\n";
        echo "  Eliminados: {$dup->deleted_records}\n";
        echo "  IDs: {$dup->liquidation_ids}\n";
        echo "  Estados: {$dup->statuses}\n";
        echo "\n";
    }
    
    echo "\nTotal de casos duplicados: " . count($duplicates) . "\n";
}
