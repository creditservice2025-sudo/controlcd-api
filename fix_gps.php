<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$clients = \App\Models\Client::all();
$healed = 0;

echo "Iniciando escaneo y reparación de GPS...\n";

foreach($clients as $c) {
    if (!$c->needs_update) {
        
        // Decodificar geolocation (Mapa Principal)
        $geo = $c->geolocation;
        if (is_string($geo)) $geo = json_decode($geo, true);
        
        if ($geo) {
            $lat = isset($geo["latitude"]) ? (float)$geo["latitude"] : -1;
            $lng = isset($geo["longitude"]) ? (float)$geo["longitude"] : -1;
            
            // Si el GPS principal está en cero, pero sí tiene dirección
            if (($lat === 0.0 || $lng === 0.0) && $c->address !== "") {
                
                // Decodificar gps_geolocalization (GPS Oculto Respaldo)
                $gps = $c->gps_geolocalization;
                if (is_string($gps)) $gps = json_decode($gps, true);
                
                $gpsLat = isset($gps["latitude"]) ? (float)$gps["latitude"] : 0;
                $gpsLng = isset($gps["longitude"]) ? (float)$gps["longitude"] : 0;
                
                // Si el GPS de respaldo SÍ tiene coordenadas válidas
                if ($gpsLat !== 0.0 && $gpsLng !== 0.0) {
                    
                    // Copiar y Guardar
                    $c->geolocation = [
                        "latitude" => (string)$gpsLat, 
                        "longitude" => (string)$gpsLng
                    ];
                    $c->save();
                    echo "Parcheado Cliente ID: " . $c->id . " - " . $c->name . "\n";
                    
                    $healed++;
                }
            }
        }
    }
}

echo "---------------------------------------------------\n";
echo "¡Completado! Total de clientes auto-reparados con éxito: " . $healed . "\n";
