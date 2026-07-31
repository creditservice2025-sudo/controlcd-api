<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve una dirección legible a partir de coordenadas.
 *
 * REGLA DE NEGOCIO: la dirección de un comentario NO puede quedar vacía. Para
 * cumplirlo hay una cascada de intentos, porque ningún proveedor externo está
 * disponible el 100% del tiempo:
 *
 *   1. Google Geocoding  — si hay GOOGLE_MAPS_API_KEY. El más preciso.
 *   2. Nominatim (OSM)   — gratuito y sin key. Respaldo cuando no hay key o
 *                          Google falla. Su política exige User-Agent propio y
 *                          como máximo 1 consulta por segundo: por eso el
 *                          backfill va espaciado y todo se cachea.
 *   3. null              — el llamador debe encolar el reintento. La ubicación
 *                          (lat/long) YA quedó guardada, así que no se pierde
 *                          nada: el comando geo:fill-addresses la completa
 *                          después (ver ClientGeolocationHistory).
 *
 * CACHÉ POR CELDA: las coordenadas se redondean a 4 decimales (~11 m) antes de
 * consultar. Varios comentarios en la misma cuadra reutilizan la respuesta y no
 * generan llamadas nuevas — en una ruta densa esto evita la mayoría de los
 * requests facturables.
 */
class ReverseGeocodeService
{
    private const CACHE_TTL_DAYS = 30;
    private const TIMEOUT_SECONDS = 4;

    /**
     * Dirección legible, o null si ningún proveedor respondió.
     * No lanza excepciones: un fallo de geocodificación jamás debe tumbar el
     * registro del comentario.
     */
    public function resolve(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        // Celda de ~11 m: agrupa puntos de la misma cuadra.
        $lat = round($latitude, 4);
        $lng = round($longitude, 4);

        try {
            $cached = Cache::get("revgeo:{$lat},{$lng}");
            if (!empty($cached)) {
                return $cached;
            }

            $address = $this->fromGoogle($lat, $lng) ?? $this->fromNominatim($lat, $lng);

            if (!empty($address)) {
                // Solo se cachean respuestas buenas: un fallo transitorio no
                // debe quedar congelado 30 días.
                Cache::put("revgeo:{$lat},{$lng}", $address, now()->addDays(self::CACHE_TTL_DAYS));
            }

            return $address;
        } catch (\Throwable $e) {
            Log::warning("ReverseGeocodeService: fallo al resolver {$lat},{$lng}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Dirección SOLO si ya está en caché. No sale a la red, así que es
     * instantáneo: pensado para listados donde hay que resolver muchas
     * ubicaciones sin que el usuario espere.
     *
     * Como la caché agrupa por celdas de ~11 m, en una ruta de cobranza —donde
     * los mismos clientes se visitan día tras día— la mayoría de los puntos ya
     * fueron resueltos antes y se responden sin una sola llamada externa.
     */
    public function fromCache(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return Cache::get('revgeo:' . round($latitude, 4) . ',' . round($longitude, 4));
    }

    /**
     * Texto SIEMPRE mostrable. Si ningún proveedor respondió, devuelve las
     * coordenadas formateadas: es información real y verificable en un mapa,
     * no un "—" que no le dice nada al administrador.
     */
    public function resolveOrCoordinates(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return $this->resolve($latitude, $longitude)
            ?? sprintf('Ubicación %.5f, %.5f', $latitude, $longitude);
    }

    private function fromGoogle(float $lat, float $lng): ?string
    {
        $apiKey = config('services.google_maps.key');
        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => "{$lat},{$lng}",
                    'key' => $apiKey,
                    'language' => 'es',
                ]);

            if (!$response->successful()) {
                return null;
            }

            return $response->json('results.0.formatted_address');
        } catch (\Throwable $e) {
            Log::warning('ReverseGeocodeService[google]: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Nominatim (OpenStreetMap). Gratuito y sin key, pero su política de uso
     * exige identificar la aplicación con un User-Agent real y no superar 1
     * consulta por segundo. Si el volumen creciera mucho, lo correcto es
     * contratar un proveedor o levantar una instancia propia de Nominatim.
     */
    private function fromNominatim(float $lat, float $lng): ?string
    {
        if (!config('services.nominatim.enabled', true)) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => config('services.nominatim.user_agent'),
                    'Accept-Language' => 'es',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $lat,
                    'lon' => $lng,
                    'zoom' => 18, // nivel de edificio/calle
                    'addressdetails' => 1,
                ]);

            if (!$response->successful()) {
                return null;
            }

            $display = $response->json('display_name');

            return !empty($display) ? $display : null;
        } catch (\Throwable $e) {
            Log::warning('ReverseGeocodeService[nominatim]: ' . $e->getMessage());
            return null;
        }
    }
}
