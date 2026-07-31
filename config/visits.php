<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alarma de "fuera de rango"
    |--------------------------------------------------------------------------
    |
    | DESACTIVADA hasta calibrar con datos propios. La medición inicial sobre
    | 802.409 pagos reales mostró que con un umbral de 150 m quedaría marcado
    | el 87% de las gestiones — no por fraude, sino porque muchas ubicaciones
    | provienen de la torre celular (error de kilómetros) y muchos domicilios
    | se georreferenciaron mal el día del alta del cliente.
    |
    | Cómo encenderla bien:
    |   1. Desplegar la captura de gps_accuracy (ya implementada).
    |   2. Dejar correr 3-4 semanas acumulando gestiones con precisión conocida.
    |   3. Mirar la distribución de distancias SOLO de las gestiones confiables
    |      (gps_source = 'gps' y accuracy <= max_accuracy_m).
    |   4. Fijar el umbral en un percentil alto de esa distribución (p95-p99),
    |      no en un número elegido a mano.
    |   5. Recién ahí poner VISITS_OUT_OF_RANGE_ENABLED=true.
    |
    | Encenderla antes significa acusar a cobradores honestos por un error de
    | medición del teléfono, y perder la credibilidad de la herramienta.
    |
    */
    'out_of_range_enabled' => env('VISITS_OUT_OF_RANGE_ENABLED', false),

    // Distancia (metros) a partir de la cual se considera fuera del domicilio.
    'distance_threshold_m' => env('VISITS_DISTANCE_THRESHOLD_M', 150),

    // Precisión máxima aceptable (metros) para que una ubicación se considere
    // utilizable. Por encima, la lectura dice más del dispositivo que del lugar.
    'max_accuracy_m' => env('VISITS_MAX_ACCURACY_M', 100),
];
