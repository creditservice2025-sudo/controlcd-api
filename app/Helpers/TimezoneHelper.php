<?php

namespace App\Helpers;

use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TimezoneHelper
{
    /**
     * Mapa de zonas horarias por país.
     * En el futuro esto debería venir de la base de datos (tabla countries).
     */
    const COUNTRY_TIMEZONES = [
        'Colombia' => 'America/Bogota',
        'Peru' => 'America/Lima',
        'Perú' => 'America/Lima',
        'Mexico' => 'America/Mexico_City',
        'México' => 'America/Mexico_City',
        'Chile' => 'America/Santiago',
        'Argentina' => 'America/Argentina/Buenos_Aires',
        'Ecuador' => 'America/Guayaquil',
        'Venezuela' => 'America/Caracas',
        // Bolivia es UTC-4. Sin esta entrada caía al default (Lima, UTC-5) y el
        // día de negocio de sus vendedores se cortaba una hora antes: lo
        // registrado entre 00:00 y 00:59 hora boliviana quedaba fechado el día
        // anterior. Corrige de acá en adelante; lo ya registrado no se toca.
        'Bolivia' => 'America/La_Paz',
        // Default fallback
        'default' => 'America/Lima'
    ];

    /**
     * Mapa de zonas horarias por código de país ISO 3166-1 alpha-2.
     * Usado por el módulo Collection, que ancla la fecha contable
     * (business_date) a la zona del país del movimiento (guardado como
     * country_code, ej. "CO", "VE"). Collection vive en una BD aislada y no
     * puede hacer join a la tabla countries del núcleo, por eso el mapa es
     * explícito. Ver timezoneForCountryCode().
     */
    const COUNTRY_CODE_TIMEZONES = [
        'CO' => 'America/Bogota',
        'PE' => 'America/Lima',
        'VE' => 'America/Caracas',
        'EC' => 'America/Guayaquil',
        'BO' => 'America/La_Paz',
        'CL' => 'America/Santiago',
        'AR' => 'America/Argentina/Buenos_Aires',
        'MX' => 'America/Mexico_City',
        'ES' => 'Europe/Madrid',
    ];

    /**
     * Resuelve la zona horaria IANA para un código de país ISO alpha-2.
     * Devuelve null si el código es desconocido, para que el llamador decida
     * el fallback (típicamente la zona de la empresa).
     */
    public static function timezoneForCountryCode(?string $code): ?string
    {
        if (!$code) {
            return null;
        }
        return self::COUNTRY_CODE_TIMEZONES[strtoupper(trim($code))] ?? null;
    }

    /**
     * Resuelve la zona horaria de negocio para un vendedor.
     * 
     * @param Seller|null $seller
     * @return string
     */
    public static function getSellerTimezone(?Seller $seller): string
    {
        if (!$seller) {
            return self::COUNTRY_TIMEZONES['default'];
        }

        try {
            // Intentar obtener país a través de la relación
            // Seller -> City -> Country
            $seller->loadMissing('city.country');
            
            if ($seller->city && $seller->city->country) {
                $countryName = $seller->city->country->name;
                
                if (isset(self::COUNTRY_TIMEZONES[$countryName])) {
                    return self::COUNTRY_TIMEZONES[$countryName];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error resolving timezone for seller {$seller->id}: " . $e->getMessage());
        }

        return self::COUNTRY_TIMEZONES['default'];
    }

    /**
     * Obtiene el timestamp de negocio actual para un vendedor.
     * 
     * @param Seller|null $seller
     * @return Carbon
     */
    public static function getBusinessNow(?Seller $seller): Carbon
    {
        $timezone = self::getSellerTimezone($seller);
        return Carbon::now($timezone);
    }

    /**
     * Trio de campos de negocio ("business_*") para estampar en un crédito al
     * momento de crearlo, anclado a la zona horaria del VENDEDOR (no a la del
     * navegador de quien lo crea). Congela el "día de negocio" de forma que no
     * dependa de quién crea ni de quién consulta después.
     *
     * Réplica del patrón ya usado en pagos (PaymentService): la hora local se
     * guarda "cruda" (mismos dígitos que se muestran en pantalla) y business_date
     * es el día calendario local. Ver formatBusinessDateTime en el frontend.
     *
     * @param Seller|null $seller
     * @param Carbon|null $moment  Instante base (por defecto "ahora"); útil para
     *                             importaciones que fijan el día de carga.
     * @return array{business_timestamp: string, business_date: string, business_timezone: string}
     */
    public static function businessStampForSeller(?Seller $seller, ?Carbon $moment = null): array
    {
        $tz = self::getSellerTimezone($seller);
        $local = $moment ? $moment->copy()->setTimezone($tz) : Carbon::now($tz);

        return [
            // Hora local del negocio, guardada tal cual (se muestra sin convertir).
            'business_timestamp' => $local->format('Y-m-d H:i:s'),
            // Día de negocio congelado: base estable para filtros y reportes.
            'business_date' => $local->toDateString(),
            'business_timezone' => $tz,
        ];
    }

    /**
     * Filtro "pertenece a esta jornada" para una tabla que tiene día de negocio
     * anclado pero arrastra filas históricas sin anclar.
     *
     * Dos ramas, y la distinción importa:
     *  - Fila CON `business_date`: se compara día contra día. No hay conversión
     *    posible de por medio, así que da igual la zona del que consulta.
     *  - Fila SIN `business_date` (histórico previo al anclaje): se cae al rango
     *    sobre `created_at`, que es el reloj GLOBAL de la app
     *    (config('app.timezone')) porque lo escribe el timestamp por defecto de
     *    Eloquent. Por eso la ventana se traduce a la zona de la APP: pasarla a
     *    UTC —como se hacía antes— la corría el equivalente al offset y perdía
     *    los registros de la madrugada mientras colaba los del día siguiente.
     *
     * Es el mismo patrón que PaymentService ya aplica sobre `payments`, y es lo
     * que permite desplegar el anclaje sin backfill previo ni ventana de
     * mantenimiento: mientras el backfill no corra, lo viejo sigue leyéndose por
     * la rama de compatibilidad.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function whereBusinessDayBetween(
        $query,
        Carbon $from,
        Carbon $to,
        string $businessDateColumn,
        string $createdAtColumn
    ) {
        $appTz = config('app.timezone') ?: 'UTC';
        $fromApp = $from->copy()->setTimezone($appTz);
        $toApp = $to->copy()->setTimezone($appTz);
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();

        return $query->where(function ($q) use (
            $businessDateColumn, $createdAtColumn, $fromApp, $toApp, $fromDay, $toDay
        ) {
            $q->whereBetween($businessDateColumn, [$fromDay, $toDay])
                ->orWhere(function ($q2) use ($businessDateColumn, $createdAtColumn, $fromApp, $toApp) {
                    $q2->whereNull($businessDateColumn)
                        ->whereBetween($createdAtColumn, [$fromApp, $toApp]);
                });
        });
    }
}
