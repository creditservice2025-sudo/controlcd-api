<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Seller;
use Carbon\Carbon;

/**
 * Calendario de negocio: fuente ÚNICA de verdad sobre si una ruta (vendedor)
 * OPERA un día dado. Reemplaza los chequeos sueltos de "isSunday" dispersos por
 * login, middleware y los comandos de liquidación.
 *
 * Un día NO es laborable cuando:
 *   1) Es día de descanso semanal de la ruta (hoy: domingo, si la config del
 *      vendedor tiene works_sundays = false).
 *   2) [FASE 2] Es feriado del país del vendedor (tabla `holidays`).
 *
 * Consumido por: LoginService (bloqueo de ingreso), CheckSellerWorkingDay
 * (expulsión de sesiones activas), AutoLiquidateSellers (no genera liquidación)
 * y CheckAutoClosures (no alerta días que la ruta no opera).
 */
class BusinessCalendar
{
    /**
     * ¿La ruta del vendedor OPERA en la fecha dada?
     *
     * @param Seller $seller
     * @param string $date  Fecha de negocio en formato Y-m-d (ya resuelta por el
     *                      caller con la zona del vendedor). El día de la semana y
     *                      el feriado se derivan de esta fecha calendario.
     */
    public static function isWorkingDate(Seller $seller, string $date): bool
    {
        $day = Carbon::parse($date);

        // 1) Descanso semanal. Default: trabaja domingos (works_sundays = true).
        //    Solo se restringe si la config está EXPLÍCITA en false.
        $config = $seller->config;
        if ($config && (int) $config->works_sundays === 0 && $day->isSunday()) {
            return false;
        }

        // 2) Feriado de la empresa para el país del vendedor.
        if (self::isHoliday($seller, $day)) {
            return false;
        }

        return true;
    }

    /**
     * ¿La fecha es feriado para el país del vendedor? Considera tanto los
     * feriados NACIONALES (company_id null, compartidos) como los propios de la
     * EMPRESA del vendedor. Matchea recurrentes (por month/day) y fechas exactas.
     */
    private static function isHoliday(Seller $seller, Carbon $day): bool
    {
        $companyId = $seller->company_id;
        $seller->loadMissing('city.country');
        $countryId = optional(optional($seller->city)->country)->id;

        if (!$companyId || !$countryId) {
            return false;
        }

        return Holiday::where('country_id', $countryId)
            ->where(function ($q) use ($companyId) {
                // Nacional (compartido) o propio de la empresa del vendedor.
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('active', true)
            ->where(function ($q) use ($day) {
                $q->where(function ($r) use ($day) {
                    $r->where('recurring', true)
                        ->where('month', (int) $day->month)
                        ->where('day', (int) $day->day);
                })->orWhere(function ($r) use ($day) {
                    $r->where('recurring', false)
                        ->whereDate('date', $day->toDateString());
                });
            })
            ->exists();
    }

    /**
     * Inverso de conveniencia: ¿es día NO laborable para la ruta?
     */
    public static function isNonWorkingDate(Seller $seller, string $date): bool
    {
        return !self::isWorkingDate($seller, $date);
    }
}
