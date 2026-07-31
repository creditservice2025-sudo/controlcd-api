<?php

namespace App\Console\Commands;

use App\Models\ClientGeolocationHistory;
use App\Services\ReverseGeocodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Completa las direcciones que quedaron sin resolver en el historial de
 * geolocalización (principalmente, la ubicación de los comentarios).
 *
 * Por qué existe: al guardar un comentario se intenta resolver la dirección en
 * el momento, pero ese intento puede fallar (proveedor caído, timeout, servidor
 * sin salida a internet en ese instante). La coordenada SIEMPRE queda guardada,
 * así que la dirección se puede completar después sin perder nada. Este comando
 * es el que cumple la regla "la dirección no puede quedar vacía".
 *
 * Ritmo: procesa pocos registros por corrida y espera entre llamadas porque
 * Nominatim (el respaldo gratuito) permite como máximo 1 consulta por segundo.
 * Con la caché por celda, las direcciones repetidas no consumen turno.
 */
class FillGeolocationAddresses extends Command
{
    protected $signature = 'geo:fill-addresses
                            {--limit=30 : Máximo de registros a procesar en esta corrida}
                            {--max-attempts=5 : Intentos antes de conformarse con las coordenadas}
                            {--days=3 : Antigüedad máxima (días) de los registros a resolver}
                            {--types=comment_created,visit_registered,payment_created : action_type a resolver (vacío = todos)}';

    protected $description = 'Resuelve las direcciones pendientes del historial de geolocalización';

    public function handle(ReverseGeocodeService $reverseGeocode): int
    {
        $limit = (int) $this->option('limit');
        $maxAttempts = (int) $this->option('max-attempts');
        $days = (int) $this->option('days');

        // SOLO REGISTROS RECIENTES. El historial arrastra ~645.000 ubicaciones
        // de pagos anteriores a esta funcionalidad, todas sin dirección. A 30
        // por corrida tardaría meses en alcanzarlas y golpearía al proveedor
        // gratuito con un volumen que su política de uso no permite — para
        // resolver direcciones de pagos viejos que nadie va a consultar.
        // Lo reciente (que es lo que el administrador mira) se completa en
        // minutos; si alguna vez hace falta el histórico, se corre a mano con
        // --days grande y un límite acorde.
        // QUÉ SE RESUELVE Y POR QUÉ ESTE RITMO.
        //
        // Se registran unas 4.000 ubicaciones de pago al día. A 1 consulta por
        // segundo eso son ~67 minutos de trabajo repartidos en 24 horas: entra
        // de sobra en el límite del proveedor gratuito (su restricción es 1
        // consulta por segundo, no un tope diario). Con este comando corriendo
        // cada 5 minutos y 30 registros por corrida se cubren hasta 8.640 al
        // día, más que suficiente para pagos + gestiones.
        //
        // Lo que NO se toca es el histórico: hay ~650.000 ubicaciones anteriores
        // a esta funcionalidad. Alcanzarlas llevaría meses y nadie las consulta.
        // Por eso el filtro por antigüedad: se resuelve lo reciente, que es lo
        // que el administrador abre. Si alguna vez hace falta el histórico, se
        // corre a mano con --days grande.
        //
        // La caché por celda (~11 m) hace que una ruta repetida día tras día
        // se resuelva casi sin llamadas externas.
        $types = array_filter(array_map('trim', explode(',', (string) $this->option('types'))));

        $pending = ClientGeolocationHistory::query()
            ->where(function ($q) {
                $q->whereNull('address')->orWhere('address', '');
            })
            ->where('address_attempts', '<', $maxAttempts)
            ->when($days > 0, fn($q) => $q->where('recorded_at', '>=', now()->subDays($days)))
            ->when(!empty($types), fn($q) => $q->whereIn('action_type', $types))
            ->orderByDesc('id') // lo más reciente primero: es lo que alguien está mirando
            ->limit($limit)
            ->get(['id', 'latitude', 'longitude', 'address_attempts']);

        if ($pending->isEmpty()) {
            $this->info('No hay direcciones pendientes.');
            return self::SUCCESS;
        }

        $resolved = 0;
        $exhausted = 0;

        foreach ($pending as $row) {
            $latitude = (float) $row->latitude;
            $longitude = (float) $row->longitude;

            $address = $reverseGeocode->resolve($latitude, $longitude);
            $attempts = $row->address_attempts + 1;

            if ($address) {
                $row->update(['address' => $address, 'address_attempts' => $attempts]);
                $resolved++;
            } elseif ($attempts >= $maxAttempts) {
                // Se agotaron los intentos: se guardan las coordenadas como
                // texto para que la pantalla nunca muestre un vacío.
                $row->update([
                    'address' => sprintf('Ubicación %.5f, %.5f', $latitude, $longitude),
                    'address_attempts' => $attempts,
                ]);
                $exhausted++;
                Log::info("geo:fill-addresses: sin dirección tras {$attempts} intentos para #{$row->id}");
            } else {
                $row->update(['address_attempts' => $attempts]);
            }

            // Política de Nominatim: 1 consulta por segundo como máximo.
            usleep(1_100_000);
        }

        $this->info("Direcciones resueltas: {$resolved} | agotadas: {$exhausted} | revisadas: {$pending->count()}");

        return self::SUCCESS;
    }
}
