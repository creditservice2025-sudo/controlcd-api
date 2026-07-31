<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Visita / gestión de cobranza sobre un cliente.
 * Ver la migración create_client_visits_table para el razonamiento del modelo.
 */
class ClientVisit extends Model
{
    use SoftDeletes;

    /** Resultados posibles de una visita (mismo orden que el enum en BD). */
    public const RESULTS = [
        'Cobrado',
        'Abono',
        // El cobrador estuvo con el cliente y no hubo dinero. Se genera solo
        // cuando se usa el botón "No pagó" de la ruta.
        'No pagó',
        'Promesa de pago',
        'No estaba',
        'Se negó',
        'Casa cerrada',
        'Ilocalizable',
        'Otro',
    ];

    /**
     * Umbral (metros) para marcar una gestión como registrada lejos del
     * domicilio del cliente.
     *
     * DESACTIVADO A PROPÓSITO (config visits.out_of_range_enabled = false).
     * La medición sobre datos reales mostró que el 87% de los pagos quedaría
     * marcado "fuera de rango" con un umbral de 150 m — no porque los
     * cobradores mientan, sino porque muchas ubicaciones vienen de la torre
     * celular (error de kilómetros) y muchos domicilios se georreferenciaron
     * mal al dar de alta al cliente. Encender la alarma con esos datos sería
     * acusar a gente honesta y quemar la confianza en la herramienta.
     *
     * La distancia SÍ se calcula y se guarda: es lo que permitirá calibrar el
     * umbral real cuando haya unas semanas de datos con gps_accuracy.
     */
    public const DISTANCE_THRESHOLD_M = 150;

    protected $fillable = [
        'uuid',
        'client_id',
        'seller_id',
        'user_id',
        'client_comment_id',
        'payment_id',
        'source',
        'result',
        'latitude',
        'longitude',
        'accuracy',
        'gps_source',
        'address',
        'distance_to_client_m',
        'business_date',
        'business_timestamp',
        'business_timezone',
        'synced_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'synced_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ClientComment::class, 'client_comment_id');
    }

    /**
     * True si la gestión se registró lejos del domicilio del cliente.
     *
     * Devuelve null (desconocido) en tres casos, y en los tres callar es más
     * correcto que afirmar:
     *   - la alarma está desactivada mientras se calibra el umbral;
     *   - el cliente no tiene domicilio georreferenciado (no hay referencia);
     *   - la ubicación vino de la red o con baja precisión, así que la
     *     distancia medida dice más del teléfono que del cobrador.
     */
    public function isOutOfRange(): ?bool
    {
        if (!config('visits.out_of_range_enabled', false)) {
            return null;
        }

        if ($this->distance_to_client_m === null) {
            return null;
        }

        if (!$this->isLocationReliable()) {
            return null;
        }

        return $this->distance_to_client_m > config('visits.distance_threshold_m', self::DISTANCE_THRESHOLD_M);
    }

    /**
     * ¿La ubicación es lo bastante buena para sacar conclusiones?
     * Una lectura de torre celular puede errar kilómetros: medir "distancia al
     * domicilio" sobre ese dato no significa nada.
     */
    public function isLocationReliable(): bool
    {
        if ($this->gps_source === 'network') {
            return false;
        }

        $maxAccuracy = config('visits.max_accuracy_m', 100);

        // Sin dato de precisión no se puede afirmar que sea confiable (los
        // registros viejos, anteriores a gps_accuracy, caen acá).
        return $this->accuracy !== null && (float) $this->accuracy <= $maxAccuracy;
    }
}
