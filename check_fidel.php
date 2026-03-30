<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Installment;
use Carbon\Carbon;

echo "Verificando credito Fidel (23722) DESPUES DEL PARCHE:\n";
$fidelInsts = Installment::where('credit_id', 23722)
    ->where('quota_number', '>=', 8)
    ->where('quota_number', '<=', 18)
    ->orderBy('quota_number')->get();

foreach ($fidelInsts as $inst) {
    $date = Carbon::parse($inst->due_date);
    if ($date->isSunday()) {
        echo "--> [DOMINGO!] ";
    }
    echo "Cuota Nro: {$inst->quota_number} | Fecha: {$inst->due_date} | Dia: " . $date->translatedFormat('l') . " | Estado: {$inst->status}\n";
}
