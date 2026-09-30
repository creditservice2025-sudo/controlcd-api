<?php

namespace App\Support;

/**
 * Qué roles se rigen por la PARAMETRIZACIÓN (Módulos y Permisos) y cuáles
 * conservan su regla fija.
 *
 * Los roles 1 a 10 existían antes de la parametrización y el sistema los trata
 * con reglas escritas en el código: Super-Admin (1), Admin (2), Cobrador (5),
 * Supervisor (6) y Cobrador-abono (7) operan en producción, y Socio (3),
 * Asistente (4), Limitado (8), Digitador (9) y Contador (10) quedan igual por
 * prudencia aunque hoy no tengan usuarios. NINGUNO cambia su comportamiento.
 *
 * La Secretaria (11) y cualquier rol que se cree de acá en adelante se rigen
 * por lo parametrizado. El frontend aplica el mismo criterio en
 * src/utils/roles.js.
 */
final class Roles
{
    public const FIJOS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public static function esParametrizable($roleId): bool
    {
        $id = (int) $roleId;

        return $id > 0 && !in_array($id, self::FIJOS, true);
    }
}
