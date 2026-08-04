<?php

namespace App\Support;

/**
 * Decide la fecha de una liquidación al cerrar/guardar, SIN confiar en la fecha
 * que manda el dispositivo. Cierra la causa raíz de los huecos/duplicados: el
 * front calculaba la fecha con el reloj del teléfono (UTC o zona equivocada) y
 * el backend la aceptaba tal cual.
 *
 * Regla:
 *  - Cobrador (rol 5) y Supervisor (rol 6): SOLO pueden cerrar el día de negocio
 *    de HOY (calculado en el servidor con la zona del vendedor). La fecha del
 *    dispositivo se ignora → un teléfono con fecha/hora mal ya no corre el día.
 *  - Admin (rol 1) y Admin de empresa (rol 2): pueden cerrar/corregir días
 *    PASADOS (se respeta la fecha pedida), pero nunca una fecha POSTERIOR al día
 *    de negocio del vendedor. Es el "corte" clásico de cierre contable: si el
 *    admin cierra a las 00:11 del domingo con el navegador ya en domingo, lo que
 *    está cerrando en realidad es el SÁBADO, y ahí se posta. Sin este tope se
 *    creaba una liquidación del domingo a rutas con works_sundays = 0, que
 *    después el auto-cierre saltea por ser día no laborable y queda 'En curso'
 *    para siempre.
 */
class LiquidationDatePolicy
{
    /** Roles cuyo cierre es "operativo" (siempre hoy): cobrador y supervisor. */
    private const OPERATIONAL_ROLES = [5, 6];

    /**
     * Fecha efectiva de la liquidación (YYYY-MM-DD).
     *
     * @param int    $role           role_id del usuario que cierra
     * @param string $requestedDate  fecha que mandó el cliente (YYYY-MM-DD)
     * @param string $businessToday  hoy en la zona del vendedor, calculado en el servidor
     */
    public static function resolveClosingDate(int $role, string $requestedDate, string $businessToday): string
    {
        // Operativos: el servidor manda; siempre hoy.
        if (in_array($role, self::OPERATIONAL_ROLES, true)) {
            return $businessToday;
        }

        // Admin: respeta la fecha pedida para corregir días PASADOS, pero nunca
        // por delante del día de negocio del vendedor. El tope es el corte: una
        // fecha posterior a hoy siempre viene del reloj de quien cierra, no del
        // día que realmente se está cerrando.
        return $requestedDate > $businessToday ? $businessToday : $requestedDate;
    }

    /** ¿La fecha es futura respecto del día de negocio de hoy? */
    public static function isFuture(string $date, string $businessToday): bool
    {
        return $date > $businessToday;
    }
}
