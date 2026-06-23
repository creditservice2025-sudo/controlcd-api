<?php

namespace App\Services\Traits;

use App\Exceptions\CashClosedException;
use App\Models\Liquidation;

/**
 * Defensa en profundidad: rechaza operaciones de mutación (crear pago,
 * gasto, ingreso, crédito) cuando la liquidación del día del vendedor
 * ya está cerrada.
 *
 * Esta es una capa adicional al middleware `liquidation.closed` que
 * invalida sesiones tras cerrar caja. Aunque el middleware sea
 * suficiente para sesiones del cobrador en otros dispositivos,
 * este guard cubre escenarios donde:
 *   - El cache lock falla (Redis caído, fail-open del middleware)
 *   - Un admin/supervisor opera por la cuenta del vendedor y se le pasa
 *   - Algún flujo nuevo no respeta el middleware
 *
 * Estados considerados "cerrada":
 *   - pending  → vendedor cerró caja, espera aprobación admin
 *   - auto     → cron cerró caja automáticamente
 *   - approved → admin ya aprobó el cierre
 *
 * 'En curso' = caja abierta, las operaciones pasan normalmente.
 * null (no hay liquidación de hoy) = caja sin abrir todavía, también pasa.
 */
trait EnforcesCashOpen
{
    /**
     * Lanza excepción si la caja del vendedor ya está cerrada para la
     * fecha de negocio dada. Usar inmediatamente después de resolver el
     * seller_id y el businessDate, antes de cualquier escritura en BD.
     *
     * @throws \Exception cuando la caja está cerrada.
     */
    protected function assertSellerCashOpen(int $sellerId, string $businessDate): void
    {
        $closed = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $businessDate)
            ->whereIn('status', ['pending', 'auto', 'approved'])
            ->exists();

        if ($closed) {
            throw new CashClosedException(
                'La liquidación del día del vendedor ya fue cerrada. ' .
                'No se pueden registrar movimientos. ' .
                'Si necesitas operar, contacta al administrador para reabrir la caja.'
            );
        }
    }
}
