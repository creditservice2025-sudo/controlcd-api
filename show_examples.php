<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$credits1 = App\Models\Credit::where('status', '!=', 'Liquidado')
    ->where('remaining_amount', '<=', 0.01)
    ->with('seller.user')
    ->limit(15)
    ->get();

$inactiveCredits = App\Models\Credit::where('status', '!=', 'Liquidado')
    ->whereNotExists(function ($query) {
        $query->select(\Illuminate\Support\Facades\DB::raw(1))
            ->from('installments')
            ->whereColumn('installments.credit_id', 'credits.id')
            ->where('installments.status', '!=', 'Pagado');
    })
    ->with('seller.user')
    ->limit(5)
    ->get();

echo "========================================================\n";
echo "EJEMPLOS DE CRÉDITOS AFECTADOS POR EL ERROR DE PRECISIÓN\n";
echo "========================================================\n\n";

echo "1. CASOS CON SALDO NEGATIVO (Sobrepagados que no se cerraron):\n";
echo "--------------------------------------------------------\n";
foreach ($credits1 as $c) {
    if ($c->remaining_amount < 0) {
        echo "- Crédito ID: " . str_pad($c->id, 5) . " | Saldo Registrado: " . str_pad($c->remaining_amount, 8) . " | Estado: " . $c->status . "\n";
    }
}
echo "\n2. CASOS CON SALDO CERO O MICRO-DECIMAL (Pagos exactos que no se cerraron):\n";
echo "--------------------------------------------------------\n";
foreach ($credits1 as $c) {
    if ($c->remaining_amount >= 0) {
        echo "- Crédito ID: " . str_pad($c->id, 5) . " | Saldo Registrado: " . str_pad($c->remaining_amount, 8) . " | Estado: " . $c->status . "\n";
    }
}

echo "\n3. CASOS DE CRÉDITOS SIN CUOTAS PENDIENTES PERO ACTIVOS:\n";
echo "--------------------------------------------------------\n";
foreach ($inactiveCredits as $c) {
    echo "- Crédito ID: " . str_pad($c->id, 5) . " | Saldo Registrado: " . str_pad($c->remaining_amount, 8) . " | Todas las cuotas están 'Pagadas'\n";
}

echo "\n========================================================\n";
