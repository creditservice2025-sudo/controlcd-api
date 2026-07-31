<?php

namespace App\Services;

use App\Helpers\TimezoneHelper;
use App\Models\Client;
use App\Models\ClientComment;
use App\Models\ClientVisit;
use App\Models\Seller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientVisitService
{
    public function __construct(
        private GeolocationHistoryService $geolocationHistory,
        private ReverseGeocodeService $reverseGeocode,
    ) {
    }

    /**
     * Registra una visita. IDEMPOTENTE por uuid: el APK genera el uuid antes de
     * enviar, así que un reintento tras una caída de señal devuelve la visita ya
     * registrada en lugar de duplicarla.
     *
     * @param  array{uuid:string, result:string, latitude:float, longitude:float,
     *               accuracy:?float, address:?string, comment:?string,
     *               comment_category_id:?int, occurred_at:?string}  $data
     */
    public function register(Client $client, array $data): ClientVisit
    {
        // Idempotencia: si ya llegó, se devuelve tal cual. Sin transacción ni
        // lock porque el índice único del uuid es la garantía real.
        $existing = ClientVisit::where('uuid', $data['uuid'])->first();
        if ($existing) {
            Log::info("ClientVisitService: visita {$data['uuid']} ya registrada, se ignora el reintento");
            return $existing;
        }

        $seller = $client->seller_id ? Seller::with('city.country')->find($client->seller_id) : null;
        $timezone = TimezoneHelper::getSellerTimezone($seller);

        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        // El día de negocio se ancla al momento en que OCURRIÓ la visita, no a
        // cuando llegó al servidor: una visita hecha a las 23:50 sin señal y
        // sincronizada al día siguiente pertenece al día en que se hizo.
        $occurredAt = !empty($data['occurred_at'])
            ? \Carbon\Carbon::parse($data['occurred_at'])->setTimezone($timezone)
            : \Carbon\Carbon::now($timezone);

        $address = $data['address'] ?? null;
        if (empty($address)) {
            $address = $this->reverseGeocode->resolve($latitude, $longitude);
        }

        return DB::transaction(function () use ($client, $data, $seller, $timezone, $latitude, $longitude, $occurredAt, $address) {
            // El comentario es opcional y viaja junto con la visita para que el
            // cobrador no tenga que hacer dos gestos en la calle.
            $comment = null;
            if (!empty($data['comment'])) {
                $comment = ClientComment::create([
                    'client_id' => $client->id,
                    'user_id' => Auth::id(),
                    'comment_category_id' => $data['comment_category_id'] ?? null,
                    'body' => trim($data['comment']),
                ]);
            }

            $visit = ClientVisit::create([
                'uuid' => $data['uuid'],
                'client_id' => $client->id,
                'seller_id' => $client->seller_id,
                'user_id' => Auth::id(),
                'client_comment_id' => $comment?->id,
                'source' => 'manual',
                'result' => $data['result'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $data['accuracy'] ?? null,
                'gps_source' => $data['gps_source'] ?? null,
                'address' => $address,
                'distance_to_client_m' => $this->distanceToClient($client, $latitude, $longitude),
                'business_date' => $occurredAt->toDateString(),
                'business_timestamp' => $occurredAt->format('Y-m-d H:i:s'),
                'business_timezone' => $timezone,
                'synced_at' => now(),
            ]);

            // La ubicación también entra al historial del cliente, que es donde
            // el sistema concentra todos los puntos (pagos, créditos, fotos).
            $this->geolocationHistory->record(
                $client->id,
                $latitude,
                $longitude,
                'visit_registered',
                'Visita registrada: ' . $data['result'],
                $visit->id,
                $address,
                $data['accuracy'] ?? null
            );

            // Si la visita trajo comentario, su ubicación es la misma: así el
            // hilo de comentarios muestra dónde se escribió sin depender de que
            // el APK capture el GPS dos veces.
            if ($comment) {
                $this->geolocationHistory->record(
                    $client->id,
                    $latitude,
                    $longitude,
                    'comment_created',
                    'Comentario de la visita ' . $visit->uuid,
                    $comment->id,
                    $address,
                    $data['accuracy'] ?? null
                );
            }

            return $visit->load(['user:id,name,role_id', 'comment']);
        });
    }

    /**
     * Deriva la gestión automáticamente desde un pago.
     *
     * Es la vía principal de registro: el pago YA captura ubicación (98% de los
     * casos), así que la presencia queda documentada sin pedirle nada al
     * cobrador. El registro manual queda para cuando NO hubo pago, que es
     * justamente cuando hace falta una explicación.
     *
     * Nunca lanza: un fallo acá jamás puede tumbar el registro de un pago.
     */
    public function registerFromPayment(
        \App\Models\Payment $payment,
        Client $client,
        ?int $commentId = null
    ): ?ClientVisit {
        try {
            if (!$payment->latitude || !$payment->longitude) {
                return null;
            }

            // Una sola gestión automática por cliente y día: si el cliente paga
            // dos créditos, sigue siendo una visita. Las manuales no se tocan,
            // porque la última gestión del día es la que vale (p. ej. "no
            // estaba" en la mañana y cobro en la tarde son dos hechos reales).
            $existing = ClientVisit::where('client_id', $client->id)
                ->where('business_date', $payment->business_date)
                ->where('source', 'pago')
                ->first();

            if ($existing) {
                return $existing;
            }

            $result = match ((string) $payment->status) {
                'Abonado' => 'Abono',
                // El botón "No pagó" de la ruta: el cobrador estuvo y no hubo
                // dinero. Es una gestión con resultado propio, no una ausencia.
                'No pagado', 'No Pagado' => 'No pagó',
                default => 'Cobrado',
            };

            $latitude = (float) $payment->latitude;
            $longitude = (float) $payment->longitude;

            $visit = ClientVisit::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'client_id' => $client->id,
                'seller_id' => $client->seller_id,
                'user_id' => $payment->created_by ?? Auth::id(),
                'payment_id' => $payment->id,
                // Motivo escrito por el cobrador (obligatorio en el "No pagó").
                'client_comment_id' => $commentId,
                'source' => 'pago',
                'result' => $result,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $payment->gps_accuracy,
                'gps_source' => $payment->gps_source,
                'address' => $payment->address,
                'distance_to_client_m' => $this->distanceToClient($client, $latitude, $longitude),
                'business_date' => $payment->business_date,
                'business_timestamp' => $payment->business_timestamp ?? now(),
                'business_timezone' => $payment->business_timezone ?? 'America/Lima',
                'synced_at' => now(),
            ]);

            return $visit;
        } catch (\Throwable $e) {
            Log::warning("ClientVisitService::registerFromPayment falló para el pago {$payment->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Distancia en metros entre el punto de la visita y el domicilio guardado
     * del cliente (fórmula de Haversine). null si el cliente no tiene GPS: sin
     * referencia no se puede afirmar nada sobre la distancia.
     */
    public function distanceToClient(Client $client, float $latitude, float $longitude): ?int
    {
        $gps = $client->gps_geolocalization ?: $client->geolocation;

        $clientLat = isset($gps['latitude']) ? (float) $gps['latitude'] : null;
        $clientLng = isset($gps['longitude']) ? (float) $gps['longitude'] : null;

        if (!$clientLat || !$clientLng) {
            return null;
        }

        $earthRadius = 6371000; // metros
        $dLat = deg2rad($clientLat - $latitude);
        $dLng = deg2rad($clientLng - $longitude);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($clientLat)) * sin($dLng / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Visitas de un cliente, más recientes primero.
     */
    public function history(Client $client, int $limit = 50)
    {
        return ClientVisit::with(['user:id,name,role_id', 'comment:id,body'])
            ->where('client_id', $client->id)
            ->orderByDesc('business_timestamp')
            ->limit($limit)
            ->get()
            ->map(fn(ClientVisit $visit) => $this->present($visit));
    }

    /**
     * Forma de salida única para la API (misma en store, historial y agenda).
     */
    public function present(ClientVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'uuid' => $visit->uuid,
            'result' => $visit->result,
            // 'pago' = derivada de una transacción (hecho verificado).
            // 'manual' = declarada por el cobrador. No deben leerse igual.
            'source' => $visit->source,
            'location_reliable' => $visit->isLocationReliable(),
            // Permite al front no duplicar la entrada cuando el comentario ya
            // se muestra junto al resultado de la visita.
            'client_comment_id' => $visit->client_comment_id,
            'author' => $visit->user->name ?? null,
            'author_role_id' => $visit->user->role_id ?? null,
            'comment' => $visit->comment->body ?? null,
            'latitude' => (float) $visit->latitude,
            'longitude' => (float) $visit->longitude,
            'accuracy' => $visit->accuracy !== null ? (float) $visit->accuracy : null,
            // Nunca vacío: si la dirección no se resolvió van las coordenadas.
            'address' => $visit->address
                ?: sprintf('Ubicación %.5f, %.5f', (float) $visit->latitude, (float) $visit->longitude),
            // true = falta el nombre de la calle; el front la pide aparte.
            'address_pending' => empty($visit->address),
            'distance_to_client_m' => $visit->distance_to_client_m,
            'out_of_range' => $visit->isOutOfRange(),
            'business_date' => $visit->business_date?->toDateString(),
            'business_timestamp' => $visit->business_timestamp,
        ];
    }
}
