<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Liquidation;

$alexisId = 41; // We found this earlier
$liquidation = Liquidation::where('seller_id', $alexisId)->whereDate('date', '2026-03-30')->first();

if ($liquidation) {
    echo "========= VALORES REALES ALMACENADOS EN BASE DE DATOS =========\n";
    echo "total_income: " . $liquidation->total_income . "\n";
    echo "total_collected: " . $liquidation->total_collected . "\n";
    echo "base_delivered: " . $liquidation->base_delivered . "\n";
    echo "poliza: " . $liquidation->poliza . "\n";
    echo "new_credits: " . $liquidation->new_credits . "\n";
    echo "total_expenses: " . $liquidation->total_expenses . "\n";
    echo "renewal_disbursed_total: " . $liquidation->renewal_disbursed_total . "\n";
    echo "irrecoverable_credits_amount: " . $liquidation->irrecoverable_credits_amount . "\n";
    echo "real_to_deliver: " . $liquidation->real_to_deliver . "\n";
    
    $cashCollection = (
        $liquidation->total_income
        + $liquidation->total_collected
        + $liquidation->base_delivered
        + $liquidation->poliza
    )
        - (
        $liquidation->new_credits
        + $liquidation->total_expenses
        + $liquidation->renewal_disbursed_total
        + $liquidation->irrecoverable_credits_amount
    );
    echo "\n=> Cash Collection (Caja del día) Calculado con estos datos: " . $cashCollection . "\n";

    echo "\n¿Por qué difiere de lo que calculamos en vivo hace unos minutos?!\n";
    
} else {
    echo "No se encontró la liquidación en la base de datos.";
}
