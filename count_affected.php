<?php
ini_set('memory_limit', '512M');

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Credit;

$credits = Credit::whereIn('status', ['Vigente', 'Vencido'])
    ->with(['installments', 'client'])
    ->get();

$affected = [];

foreach ($credits as $credit) {
    $sum = round($credit->installments->sum('quota_amount'), 2);
    $diff = round($sum - $credit->total_amount, 2);

    if (abs($diff) > 0.001) {
        // Ignorar créditos con total_amount = 0 (datos corruptos aparte)
        $isCorrupt = ($credit->total_amount == 0);
        $affected[] = [
            'id'      => $credit->id,
            'cliente' => $credit->client->name ?? 'Sin nombre',
            'total'   => $credit->total_amount,
            'suma'    => $sum,
            'diff'    => $diff,
            'corrupt' => $isCorrupt,
        ];
    }
}

$realIssues = array_filter($affected, fn($a) => !$a['corrupt']);

echo "=================================================\n";
echo " CRÉDITOS AFECTADOS POR REDONDEO DE CUOTAS\n";
echo "=================================================\n\n";

if (count($realIssues) > 0) {
    printf("%-10s %-40s %10s %10s %8s\n", "Crédito", "Cliente", "Total", "Suma", "Dif.");
    echo str_repeat("-", 82) . "\n";
    foreach ($realIssues as $a) {
        printf("%-10s %-40s %10s %10s %8s\n",
            '#' . $a['id'],
            mb_substr($a['cliente'], 0, 38),
            '$' . $a['total'],
            '$' . $a['suma'],
            '$' . $a['diff']
        );
    }
} else {
    echo "No se encontraron créditos con diferencias de redondeo.\n";
}

echo "\n=================================================\n";
echo " Total vigentes analizados : " . $credits->count() . "\n";
echo " Afectados por redondeo    : " . count($realIssues) . "\n";
echo "=================================================\n";
