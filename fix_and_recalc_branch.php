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
        echo "Ingreso 269 encontrado con business_date: " . ($income->business_date instanceof Carbon ? $income->business_date->toDateString() : $income->business_date) . "\n";
        
        // El usuario quiere que sea el 18
        if ($income->getAttributes()['business_date'] !== '2026-02-18') {
             echo "Cambiando business_date a 2026-02-18...\n";
             $income->business_date = '2026-02-18';
             // Asegurar timestamp normalizado
             $income->business_timestamp = Carbon::parse('2026-02-18 05:33:01', 'UTC');
             $income->save();
             echo "Ajuste de fecha completado.\n";
        } else {
             echo "La fecha ya es 2026-02-18.\n";
        }
    }

    $seller = Seller::where('user_id', 60)->first();
    if ($seller) {
        echo "Recalculando liquidaciones para vendedor {$seller->id} (User 60)...\n";
        $ls = app(LiquidationService::class);
        
        // Limpiar caché de métricas explícitamente si existe
        app(\App\Services\MetricsCacheService::class)->invalidateLiquidationMetrics($seller->id, '2026-02-17');
        app(\App\Services\MetricsCacheService::class)->invalidateLiquidationMetrics($seller->id, '2026-02-18');
        
        $ls->recalculateLiquidation($seller->id, '2026-02-17');
        $ls->recalculateLiquidation($seller->id, '2026-02-18');
        echo "Liquidaciones frescas regeneradas.\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
