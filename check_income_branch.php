<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Income;
use Carbon\Carbon;

$i = Income::find(269);
if (!$i) {
    echo "Registro 269 no encontrado.\n";
    exit;
}

echo "ID: " . $i->id . "\n";
echo "Value: " . $i->value . "\n";
echo "Business Date (Raw): " . $i->getAttributes()['business_date'] . "\n";
echo "Business Date (Cast): " . ($i->business_date instanceof Carbon ? $i->business_date->toDateString() : $i->business_date) . "\n";
echo "Business Timestamp: " . $i->business_timestamp . "\n";
echo "Created At (UTC): " . $i->created_at . "\n";
echo "Current App Timezone: " . config('app.timezone') . "\n";
echo "Created At (Local - America/Bogota): " . $i->created_at->setTimezone('America/Bogota')->toDateTimeString() . "\n";

// Verificar liquidaciones para ese usuario
$seller = App\Models\Seller::where('user_id', $i->user_id)->first();
echo "Seller ID: " . ($seller ? $seller->id : 'N/A') . "\n";

// Simular el cálculo de liquidación para el 18
$ls = app(App\Services\LiquidationService::class);
$metrics18 = $ls->calculateLiquidationMetrics($seller->id, '2026-02-18');
echo "Total Income for 2026-02-18: " . ($metrics18['total_income'] ?? 0) . "\n";

$metrics17 = $ls->calculateLiquidationMetrics($seller->id, '2026-02-17');
echo "Total Income for 2026-02-17: " . ($metrics17['total_income'] ?? 0) . "\n";
