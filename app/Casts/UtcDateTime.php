<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Columna de fecha/hora cuyo contenido YA está en UTC.
 *
 * El cast `datetime` de Eloquent asume que lo guardado está en la zona de la
 * app (APP_TIMEZONE, acá America/Lima). Cuando la columna guarda UTC —como
 * `collection_daily_records.recorded_at`, que el servicio escribe con
 * `->utc()`— ese supuesto le suma el offset una segunda vez: un movimiento de
 * las 20:54 de Lima se guardaba correctamente como 01:54 UTC y salía por la
 * API como 06:54Z, que el cliente pintaba como la 01:54. Cinco horas de más,
 * y el día cambiado.
 *
 * Este cast declara la convención explícitamente: se lee interpretando UTC y
 * se escribe convirtiendo a UTC. Así el ISO que viaja al cliente apunta al
 * instante real, y cada consumidor lo muestra en la zona que le corresponda
 * (la del país del movimiento, en el caso de Collection).
 *
 * Es la misma convención que ya da por sentada CollectionBackfillBusinessDates:
 * "daily_records: recorded_at está en UTC".
 *
 * OJO: no aplicar a columnas que guardan reloj de pared local (ej.
 * `payments.business_timestamp`, casteada como string a propósito). Ahí el
 * valor no es un instante y convertirlo lo rompería.
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // La zona se pasa al parsear, no después: `parse()->setTimezone('UTC')`
        // interpretaría primero en la zona del proceso y ya habría corrido la
        // hora antes de convertir.
        return $value instanceof Carbon
            ? $value->copy()->utc()
            : Carbon::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Lo que llega del servicio ya viene en UTC, así que ->utc() no mueve
        // nada; está para que un valor con otra zona tampoco se guarde mal.
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
