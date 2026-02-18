<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use App\Models\Seller;
use App\Services\LiquidationService;
use Carbon\Carbon;

try {
    $income = Income::find(269);
    if ($income) {
        echo "Cambiando business_date de 269 de vuelta a 2026-02-17...\n";
        $income->business_date = '2026-02-17';
        // El timestamp indica 00:33:01 Bogota del 18, lo mantenemos pero la fecha de negocio es el 17.
        $income->save();
        echo "Ajuste de fecha completado.\n";
    }

    $seller = Seller::where('user_id', 60)->first();
    if ($seller) {
        echo "Recalculando liquidaciones para vendedor {$seller->id}...\n";
        $ls = app(LiquidationService::class);
        $cache = app(\App\Services\MetricsCacheService::class);
        
        $cache->invalidateLiquidationMetrics($seller->id, '2026-02-17');
        $cache->invalidateLiquidationMetrics($seller->id, '2026-02-18');
        
        $ls->recalculateLiquidation($seller->id, '2026-02-17');
        $ls->recalculateLiquidation($seller->id, '2026-02-18');
        echo "Liquidaciones frescas regeneradas.\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
