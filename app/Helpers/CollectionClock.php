<?php

namespace App\Helpers;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reloj de Collection: devuelve el instante REAL de un timestamp guardado.
 *
 * Por que hace falta. La app corre en UTC (APP_TIMEZONE) y manda a Postgres el
 * timestamp como texto plano ("2026-08-29 18:51:35", que es la hora UTC). La
 * conexion de Collection no fija zona, asi que Postgres lo lee con la zona del
 * servidor —America/Caracas en este momento— y lo guarda como 18:51:35-04, o
 * sea cuatro horas DESPUES del instante real. Al leerlo de vuelta y mandarlo
 * como ISO, el front lo pasa a hora local y muestra 18:51 una adicion que se
 * hizo a las 14:51.
 *
 * La correccion suma el offset real de la conexion, que es exactamente el
 * desfase introducido. Si algun dia se arregla la config de la conexion
 * ('timezone' => 'UTC'), el offset pasa a 0 y esto se vuelve un no-op solo:
 * no hay que acordarse de deshacerlo.
 *
 * Se usa SOLO para mostrar. Los calculos de negocio (cortes, dias contables)
 * van por business_date, que no depende de esto.
 */
class CollectionClock
{
    private const CONNECTION = 'collection_pgsql';

    /** ISO-8601 del instante real, listo para mandar al front. */
    public static function iso($value): ?string
    {
        $moment = self::real($value);

        return $moment ? $moment->toISOString() : null;
    }

    /** El instante real como Carbon (null si el valor viene vacio o ilegible). */
    public static function real($value): ?CarbonInterface
    {
        if (empty($value)) {
            return null;
        }

        if (!$value instanceof CarbonInterface) {
            try {
                $value = Carbon::parse($value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $value->copy()->addSeconds(self::offsetSeconds());
    }

    /** Offset en segundos de la zona horaria de la conexion Postgres (-14400 para -04). */
    public static function offsetSeconds(): int
    {
        static $cached = null;

        if ($cached === null) {
            try {
                $row = DB::connection(self::CONNECTION)
                    ->select('SELECT EXTRACT(TIMEZONE FROM now()) AS offset_seconds');
                $cached = (int) ($row[0]->offset_seconds ?? 0);
            } catch (\Throwable $e) {
                $cached = 0;
            }
        }

        return $cached;
    }
}
