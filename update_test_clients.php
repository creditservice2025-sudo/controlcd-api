<?php

use App\Models\Client;
use Carbon\Carbon;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$clients = ['900111', '900222'];

foreach ($clients as $dni) {
    $client = Client::where('dni', $dni)->first();
    if ($client) {
        $credit = $client->credits()->first();
        if ($credit) {
            $credit->start_date = '2026-02-01';
            $credit->first_quota_date = '2026-02-04'; // Hoy
            $credit->status = 'Vigente';
            $credit->save();
            
            // Re-generar cuotas seria ideal pero es complejo, por ahora solo movemos fechas
            // Ojo: Si las cuotas ya estan generadas con fechas viejas, no saldran en el reporte de HOY
            // Necesitamos actualizar las fechas de las cuotas tambien
            
            $installments = $credit->installments()->orderBy('quota_number')->get();
            $baseDate = Carbon::parse('2026-02-04');
            
            foreach ($installments as $index => $installment) {
                $installment->due_date = $baseDate->copy()->addDays($index); // Diario/Semanal simplificado
                $installment->save();
            }
            
            echo "Updated Client: {$client->name} dates to 2026-02-04\n";
        }
    }
}
