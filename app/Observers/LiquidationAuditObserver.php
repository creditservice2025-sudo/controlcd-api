<?php

namespace App\Observers;

use App\Models\Liquidation;
use App\Models\LiquidationAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * UNA SOLA PUERTA para la auditoría de la caja.
 *
 * Hasta ahora el estado de una liquidación se podía cambiar desde nueve
 * lugares distintos (storeLiquidation, updateLiquidation, approve,
 * approveMultiple, reopenRoute, adjustBox, el cron de auto-cierre, los
 * comandos de mantenimiento y los scripts sueltos de la raíz) y NINGUNO
 * dejaba rastro del cambio de estado. Por eso, cuando una caja se descuadra,
 * no se puede reconstruir quién la movió: el dato no se perdía, nunca se
 * grababa.
 *
 * Este observer se engancha al modelo, así que cubre TODAS esas puertas de
 * una sola vez —incluidas las de consola— sin tener que tocar cada flujo.
 *
 * Qué se audita y qué NO:
 *
 *   SÍ  · status, seller_id, date  → identidad y estado del día.
 *   SÍ  · initial_cash, base_delivered, cash_delivered → los montos que se
 *         cargan a mano y que mueven el faltante/sobrante.
 *   SÍ  · alta (apertura), baja (soft-delete) y restauración del día.
 *   NO  · total_collected, total_expenses, total_income, new_credits,
 *         real_to_deliver, shortage, surplus y demás derivados: los recalcula
 *         el motor a cada rato desde las operaciones reales. Auditarlos
 *         llenaría la tabla de ruido sin agregar información (el "quién" de
 *         esos montos son los pagos/gastos/créditos, que ya tienen su propia
 *         trazabilidad).
 *
 * Nunca interrumpe la operación: si la auditoría falla, se loguea y el
 * guardado sigue. Una caja que no se puede cerrar es peor que una caja
 * cerrada sin registro —pero el log deja el aviso para que no pase inadvertido.
 */
class LiquidationAuditObserver
{
    /**
     * Campos cuyo cambio deja rastro obligatorio.
     */
    private const TRACKED = [
        'status',
        'seller_id',
        'date',
        'initial_cash',
        'base_delivered',
        'cash_delivered',
    ];

    /**
     * De los anteriores, cuáles se comparan como número (para no registrar
     * "2444.00 → 2444" como si fuera un cambio real).
     */
    private const NUMERIC = [
        'initial_cash',
        'base_delivered',
        'cash_delivered',
    ];

    public function created(Liquidation $liquidation): void
    {
        // La apertura se registra como acto del SISTEMA (user_id null) aunque
        // la dispare la sesión de un cobrador: abrir el día es automático (lo
        // provoca el primer movimiento o el login), no es una decisión suya.
        // Quién estaba operando queda igual en `changes.actor`.
        //
        // Ojo, esto NO es cosmético: el front usa la presencia de una
        // auditoría con el user_id del cobrador para habilitarle acciones al
        // administrador (hasAuditForSeller). Firmar la apertura con el
        // cobrador cambiaría ese comportamiento sin que nadie lo pidiera.
        $this->record($liquidation, 'apertura', [
            'estado'       => $liquidation->status,
            'saldo_inicial' => $this->num($liquidation->initial_cash),
        ], null);
    }

    public function updated(Liquidation $liquidation): void
    {
        $cambios = [];

        foreach (self::TRACKED as $campo) {
            if (!array_key_exists($campo, $liquidation->getChanges())) {
                continue;
            }

            $de = $liquidation->getOriginal($campo);
            $a  = $liquidation->getAttribute($campo);

            if (in_array($campo, self::NUMERIC, true)) {
                if ($this->num($de) === $this->num($a)) {
                    continue; // mismo número escrito distinto: no es un cambio
                }
                $de = $this->num($de);
                $a  = $this->num($a);
            } else {
                $de = $this->scalar($de);
                $a  = $this->scalar($a);
                if ($de === $a) {
                    continue;
                }
            }

            $cambios[$campo] = ['de' => $de, 'a' => $a];
        }

        if (empty($cambios)) {
            return;
        }

        // El cambio de estado manda: es el que define el ciclo de vida de la
        // caja y el que hay que poder rastrear meses después.
        $accion = array_key_exists('status', $cambios) ? 'cambio_estado' : 'ajuste_montos';

        $this->record($liquidation, $accion, $cambios);
    }

    public function deleted(Liquidation $liquidation): void
    {
        $this->record($liquidation, 'baja_dia', [
            'estado'         => $liquidation->status,
            'saldo_inicial'  => $this->num($liquidation->initial_cash),
            'a_entregar'     => $this->num($liquidation->real_to_deliver),
            'motivo'         => 'soft-delete',
        ]);
    }

    public function restored(Liquidation $liquidation): void
    {
        $this->record($liquidation, 'alta_dia', [
            'estado' => $liquidation->status,
        ]);
    }

    /**
     * Graba la auditoría. `$userId` en false significa "resolver del contexto";
     * null explícito significa "acto del sistema".
     */
    private function record(Liquidation $liquidation, string $accion, array $detalle, int|null|false $userId = false): void
    {
        try {
            $actor = Auth::id();

            LiquidationAudit::create([
                'liquidation_id' => $liquidation->id,
                'user_id'        => $userId === false ? $actor : $userId,
                'action'         => $accion,
                'changes'        => array_merge($detalle, [
                    'origen' => $this->origen(),
                    'actor'  => $actor,
                    'dia'    => $this->scalar($liquidation->date),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('[liquidation.audit] no se pudo registrar la auditoría', [
                'liquidation_id' => $liquidation->id ?? null,
                'accion'         => $accion,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * De dónde vino el cambio. Es la diferencia entre "lo hizo el cron" y "lo
     * hizo alguien por la API", que es justo lo que hoy no se puede saber.
     */
    private function origen(): string
    {
        if (app()->runningInConsole()) {
            $comando = implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3));
            return trim('consola ' . $comando);
        }

        $request = request();

        return trim('http ' . $request->method() . ' ' . $request->path());
    }

    private function num(mixed $valor): float
    {
        return round((float) $valor, 2);
    }

    private function scalar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        return (string) $valor;
    }
}
