<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Configura la fecha y el vendedor a probar
// Usa el vendedor y la fecha que creas que el usuario está viendo
// En el screenshot el usuario "Pablo" (seller_id 16?) está en 2026-01-05
$testSellerId = 24; // Pongo 24 porque la respuesta anterior devolvió a "Pablo" con seller_id 16 y "Johnnanci1" con 24.
// Espera, el screenshot anterior muestra "Pablo - Vendedor: Pablo".
// El script anterior 'reproduce_issue.php' mostró: [seller_id] => 16, [seller_name] => Pablo.
// ASÍ QUE PROBARÉ CON ID 16.
$sellerId = 16; 
$date = date('Y-m-d'); // Hoy
// $date = '2025-12-29'; // La fecha del crédito en el screenshot es 2025-12-29, pero la fecha de liquidación suele ser HOY.

echo "Debugging Liquidation for Seller: $sellerId on Date: $date\n";

$timezone = 'America/Lima';
$formattedDate = \Carbon\Carbon::parse($date, $timezone)->format('Y-m-d');
echo "Formatted Business Date: $formattedDate\n";

// 1. Check Payments Raw
$payments = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
    ->where('credits.seller_id', $sellerId)
    ->where('payments.business_date', $formattedDate)
    ->select('payments.id', 'payments.amount', 'payments.payment_method', 'payments.status', 'payments.business_date')
    ->get();

echo "\n--- Raw Payments for $formattedDate ---\n";
if ($payments->isEmpty()) {
    echo "No payments found for this business_date.\n";
}
foreach ($payments as $p) {
    echo "ID: {$p->id} | Amount: {$p->amount} | Method: {$p->payment_method} | Status: {$p->status} | Date: {$p->business_date}\n";
}

// 2. Check Total Calculation logic
$query = \Illuminate\Support\Facades\DB::table('payments')
    ->join('credits', 'payments.credit_id', '=', 'credits.id')
    ->select(
        'payments.payment_method',
        \Illuminate\Support\Facades\DB::raw('SUM(payments.amount) as total')
    )
    ->whereNull('payments.deleted_at')
    ->where('payments.business_date', $formattedDate)
    ->where('credits.seller_id', $sellerId)
    ->whereIn('payments.status', ['Pagado', 'Aprobado', 'Abonado'])
    ->groupBy('payments.payment_method');

$results = $query->get();
echo "\n--- Aggregated Results (Logic from Controller) ---\n";
foreach ($results as $r) {
    echo "Method: {$r->payment_method} | Total: {$r->total}\n";
}

// 3. Check Initial Cash (Last Liquidation)
$lastLiquidation = \App\Models\Liquidation::where('seller_id', $sellerId)
    ->where('date', '<', $date)
    ->orderBy('date', 'desc')
    ->first();

echo "\n--- Last Liquidation ---\n";
if ($lastLiquidation) {
    echo "ID: {$lastLiquidation->id} | Date: {$lastLiquidation->date} | Real To Deliver: {$lastLiquidation->real_to_deliver}\n";
} else {
    echo "No previous liquidation found.\n";
}
