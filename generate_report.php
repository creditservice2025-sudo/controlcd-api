<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$credits1 = App\Models\Credit::where('status', '!=', 'Liquidado')
    ->where('remaining_amount', '<=', 0.01)
    ->with(['seller.user', 'client'])
    ->get();

$inactiveCredits = App\Models\Credit::where('status', '!=', 'Liquidado')
    ->whereNotExists(function ($query) {
        $query->select(\Illuminate\Support\Facades\DB::raw(1))
            ->from('installments')
            ->whereColumn('installments.credit_id', 'credits.id')
            ->where('installments.status', '!=', 'Pagado');
    })
    ->with(['seller.user', 'client'])
    ->get();

$allAffected = $credits1->merge($inactiveCredits);

$filename = 'Creditos_Afectados_Por_Precision.csv';
$fp = fopen($filename, 'w');

// Add BOM for Excel UTF-8 display compatibility
fputs($fp, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));

fputcsv($fp, ['ID Crédito', 'Cliente', 'ID Vendedor', 'Nombre Vendedor', 'Monto Crédito', 'Saldo Restante', 'Estado Actual', 'Razón Afectación'], ';');

foreach ($allAffected as $c) {
    if ($c->remaining_amount <= 0.01) {
        $razon = 'Saldo menor o igual a 0.01';
    } else {
        $razon = 'Sin cuotas pendientes';
    }

    $vendedorName = ($c->seller && $c->seller->user) ? $c->seller->user->name : 'N/A';
    $clienteName = $c->client ? $c->client->name : 'N/A';

    fputcsv($fp, [
        $c->id,
        $clienteName,
        $c->seller_id,
        $vendedorName,
        $c->total_amount,
        $c->remaining_amount,
        $c->status,
        $razon
    ], ';');
}

fclose($fp);
echo "Report generated at: " . realpath($filename) . "\n";
