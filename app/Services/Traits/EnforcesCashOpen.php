<?php

namespace App\Services\Traits;

use App\Exceptions\CashClosedException;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Services\BusinessCalendar;

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

    /**
     * Variante para EGRESOS: si la liquidación del día fue APROBADA por el
     * administrador (status 'approved'), bloquea a TODOS —incluido el admin—
     * porque esa caja queda sellada (ni siquiera se puede reabrir). Si está
     * 'pending'/'auto' (cerró el vendedor o el cron), bloquea solo a los roles
     * operativos; el admin aún puede ajustar antes de aprobar.
     *
     * @throws CashClosedException
     */
    protected function assertExpenseCashOpen(int $sellerId, string $businessDate, bool $isAdmin): void
    {
        $liq = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $businessDate)
            ->whereIn('status', ['pending', 'auto', 'approved'])
            ->orderByDesc('id')
            ->first();

        if (!$liq) {
            return; // 'En curso' o sin liquidación → caja abierta.
        }

        if ($liq->status === 'approved') {
            throw new CashClosedException(
                'La liquidación del día ya fue cerrada por el administrador. ' .
                'No se pueden registrar egresos.'
            );
        }

        // pending / auto: bloquea a los roles operativos, no al admin.
        if (!$isAdmin) {
            throw new CashClosedException(
                'La liquidación del día del vendedor ya fue cerrada. ' .
                'No se pueden registrar movimientos. ' .
                'Si necesitas operar, contacta al administrador para reabrir la caja.'
            );
        }
    }

    /**
     * Rechaza el movimiento si la ruta NO opera ese día de negocio (día de
     * descanso semanal —domingo sin works_sundays— o feriado del país), según
     * BusinessCalendar. Aplica a TODOS (incluido el admin): si la ruta no
     * trabaja ese día, no debe existir egreso ni ingreso para esa fecha.
     *
     * @throws CashClosedException
     */
    protected function assertSellerWorksOnDate(?Seller $seller, string $businessDate): void
    {
        if ($seller && BusinessCalendar::isNonWorkingDate($seller, $businessDate)) {
            throw new CashClosedException(
                'La ruta no opera este día (día de descanso o feriado). ' .
                'No se pueden registrar movimientos.'
            );
        }
    }
}
