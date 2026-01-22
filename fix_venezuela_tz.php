<?php

use App\Models\Expense;
use App\Models\Seller;
use App\Helpers\TimezoneHelper;
use App\Services\LiquidationService;
use Carbon\Carbon;

// Forzar búsqueda para usuario 23
$targetSeller = Seller::where('user_id', 23)->first();

if ($targetSeller) {
    echo "Seller 23 found. City ID: " . ($targetSeller->city_id ?? 'NULL') . "\n";
    if ($targetSeller->city) {
         echo "City Name: " . $targetSeller->city->name . "\n";
         if ($targetSeller->city->country) {
             echo "Country Name: " . $targetSeller->city->country->name . "\n";
         } else {
             echo "Country relation missing.\n";
         }
    } else {
        echo "City relation missing.\n";
    }

    $sellersInVenezuela = collect([$targetSeller]);
} else {
    $sellersInVenezuela = collect([]);
}

foreach ($sellersInVenezuela as $seller) {
    // Buscar gastos creados "hoy" (en UTC puede ser ayer/hoy) que tengan timezone incorrecto
    // O simplemente forzar actualización de los últimos gastos.
    
    $expenses = Expense::where('user_id', $seller->user_id)
        ->where('created_at', '>=', Carbon::now()->subDays(1)) // Últimas 24h
        ->get();

    foreach ($expenses as $expense) {
        $correctTz = TimezoneHelper::getSellerTimezone($seller); // Ahora debería devolver America/Caracas
            
        // Si el gasto tiene timezone incorrecto o queremos asegurar coherencia
        if ($expense->business_timezone !== $correctTz) {
            echo "Corrigiendo Gasto {$expense->id}...\n";
            echo "Old TZ: {$expense->business_timezone} -> New TZ: {$correctTz}\n";
            
            $expense->business_timezone = $correctTz;
            // Recalculamos timestamp y date basados en el created_at (UTC)
            $expense->business_timestamp = Carbon::parse($expense->created_at)->setTimezone($correctTz);
            $expense->business_date = $expense->business_timestamp->toDateString();
            $expense->save();
            
            echo "Nueva Fecha de Negocio: {$expense->business_date}\n";

            // Recalcular liquidación para la NUEVA fecha
            // También deberíamos recalcular para la VIEJA fecha si cambió (para quitarlo de allá), 
            // pero el sistema recalcula 'on demand' o overwrite. 
            // recalculateLiquidation recalcula todo el día, así que si el gasto ya 'no pertenece' a ese día, 
            // simplemente 'desaparecerá' del total de ese día al recalcular. Perfecto.
            
            // Recalcular AMBAS fechas por si acaso se movió de día.
            // 1. Fecha nueva
            app(LiquidationService::class)->recalculateLiquidation($seller->id, $expense->business_date);
            
            // 2. Si la fecha cambió, recalculamos la vieja también para limpiar
            /*
             (No tenemos la fecha vieja guardada fácilmente aquí a menos que la guardáramos antes, 
              pero recalculando el rango de días cercanos es seguro).
             */
             $yesterday = Carbon::parse($expense->business_date)->subDay()->toDateString();
             app(LiquidationService::class)->recalculateLiquidation($seller->id, $yesterday);

             echo "Liquidaciones recalculadas.\n";
        }
    }
}
echo "Corrección completada.\n";
