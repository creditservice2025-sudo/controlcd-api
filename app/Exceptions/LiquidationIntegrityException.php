<?php

namespace App\Exceptions;

/**
 * Regla de negocio del ciclo de vida de la caja, no fallo técnico.
 *
 * Se lanza cuando una operación rompería la integridad de la cadena de
 * liquidaciones:
 *
 *  - Abrir un día DETRÁS de un día ya aprobado (inserción retroactiva). Es lo
 *    que dejó al vendedor 19 con el 2025-11-11 abierto bajo 242 días
 *    aprobados y bloqueó las consultas durante 9 meses.
 *  - Borrar un día que tiene movimientos: la plata de ese día desaparece de
 *    la cadena, porque el saldo inicial del día siguiente se toma del último
 *    día NO borrado. Así se fueron ~$110k en 34 días del histórico.
 *  - Borrar en duro (forceDelete) una liquidación: la FK
 *    `liquidation_audits.liquidation_id` es ON DELETE CASCADE, así que se
 *    llevaría puesta su propia auditoría.
 *
 * Se renderiza como 422 (ver bootstrap/app.php), nunca como 500: el usuario
 * tiene que leer el motivo, no un "error del sistema".
 */
class LiquidationIntegrityException extends \Exception
{
}
