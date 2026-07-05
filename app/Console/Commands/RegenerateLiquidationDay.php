<?php

namespace App\Console\Commands;

use App\Helpers\TimezoneHelper;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Services\LiquidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera una liquidación diaria FALTANTE (hueco) con las operaciones reales de
 * ese día (pagos, créditos, gastos, ingresos) y re-encadena las liquidaciones
 * siguientes. Reproduce, de forma segura y auditable, la corrección manual del
 * caso Nazaret (seller 202, 2026-07-01).
 *
 * Causa del hueco: la fecha de la liquidación se calculaba en UTC en el front
 * (new Date().toISOString()) y el backend no la validaba, de modo que un cierre
 * en la tarde-noche local (ya día siguiente en UTC) saltaba un día.
 *
 * SEGURO PARA PRODUCCIÓN:
 *  - Dry-run por defecto: sin --apply proyecta el resultado (creando y haciendo
 *    ROLLBACK dentro de una transacción) sin escribir nada.
 *  - Transaccional e idempotente: si ya existe liquidación para esa fecha, no
 *    hace nada.
 *  - Usa las funciones nativas del servicio (getOrCreate + recalculate +
 *    recalculateNext), las mismas que el flujo normal de cierre.
 *
 * Uso:
 *   php artisan liquidations:regenerate-day 202 2026-07-01           # dry-run
 *   php artisan liquidations:regenerate-day 202 2026-07-01 --apply   # aplica
 */
class RegenerateLiquidationDay extends Command
{
    protected $signature = 'liquidations:regenerate-day
                            {seller : ID del vendedor}
                            {date : Fecha faltante (YYYY-MM-DD)}
                            {--apply : Aplica los cambios (por defecto dry-run, no escribe)}';

    protected $description = 'Genera una liquidación diaria faltante con las operaciones del día y re-encadena las siguientes';

    public function handle(LiquidationService $svc): int
    {
        $sellerId = (int) $this->argument('seller');
        $date = (string) $this->argument('date');
        $apply = (bool) $this->option('apply');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error("Fecha inválida: {$date}. Usa formato YYYY-MM-DD.");
            return self::FAILURE;
        }

        $seller = Seller::with('city.country', 'user')->find($sellerId);
        if (!$seller) {
            $this->error("Vendedor #{$sellerId} no existe.");
            return self::FAILURE;
        }
        $tz = TimezoneHelper::getSellerTimezone($seller);

        // Idempotencia: si ya hay liquidación viva para esa fecha, nada que hacer.
        if (Liquidation::where('seller_id', $sellerId)->whereDate('date', $date)->exists()) {
            $this->info("Ya existe una liquidación para el vendedor #{$sellerId} en {$date}. Nada que hacer.");
            $this->renderChain($sellerId, $date, 'CADENA ACTUAL');
            return self::SUCCESS;
        }

        // Ancla de cadena (para initial_cash).
        $prev = Liquidation::where('seller_id', $sellerId)->where('date', '<', $date)
            ->orderBy('date', 'desc')->first();
        if (!$prev) {
            $this->warn("No hay liquidación previa a {$date}; initial_cash partirá de 0.");
        }
        if (Carbon::parse($date, $tz)->startOfDay()->gt(Carbon::now($tz)->startOfDay())) {
            $this->warn("Atención: {$date} es una fecha futura en la zona del vendedor ({$tz}).");
        }

        $this->line("Vendedor #{$sellerId} (" . ($seller->user->name ?? '—') . ") · zona {$tz} · fecha {$date}");
        $this->renderChain($sellerId, $date, 'CADENA ACTUAL');

        $run = function () use ($svc, $sellerId, $date, $tz) {
            $svc->getOrCreateLiquidation($sellerId, $date, $tz);
            $svc->recalculateLiquidation($sellerId, $date);
            $svc->recalculateNextLiquidations($sellerId, $date);
        };

        if (!$apply) {
            // Dry-run: ejecuta dentro de una transacción y REVIERTE, para proyectar
            // los números exactos (misma ruta que --apply) sin persistir nada.
            DB::beginTransaction();
            try {
                $run();
                $this->renderDayDetail($sellerId, $date, 'PROYECCIÓN DEL DÍA (dry-run)');
                $this->renderChain($sellerId, $date, 'CADENA PROYECTADA');
            } finally {
                DB::rollBack();
            }
            $this->info('DRY-RUN: no se escribió nada. Ejecuta con --apply para aplicar.');
            return self::SUCCESS;
        }

        DB::transaction($run);
        $this->info("Liquidación de {$date} generada para el vendedor #{$sellerId}.");
        $this->renderDayDetail($sellerId, $date, 'LIQUIDACIÓN GENERADA');
        $this->renderChain($sellerId, $date, 'CADENA RESULTANTE');

        return self::SUCCESS;
    }

    /** Detalle de las operaciones capturadas para el día. */
    private function renderDayDetail(int $sellerId, string $date, string $label): void
    {
        $l = Liquidation::where('seller_id', $sellerId)->whereDate('date', $date)->first();
        if (!$l) {
            $this->warn("(sin registro para {$date})");
            return;
        }
        $this->line('');
        $this->line("== {$label} — {$date} ==");
        $this->table(
            ['caja inicial', 'recaudo', 'créditos', 'gastos', 'ingresos', 'real a entregar', 'clientes pagaron', 'estado'],
            [[
                number_format((float) $l->initial_cash, 2),
                number_format((float) $l->total_collected, 2),
                number_format((float) $l->new_credits, 2),
                number_format((float) $l->total_expenses, 2),
                number_format((float) $l->total_income, 2),
                number_format((float) $l->real_to_deliver, 2),
                $l->clients_paid_count,
                $l->status,
            ]]
        );
    }

    /** Cadena de liquidaciones alrededor de la fecha (día-1 .. día+6). */
    private function renderChain(int $sellerId, string $date, string $label): void
    {
        $from = Carbon::parse($date)->subDay()->toDateString();
        $to = Carbon::parse($date)->addDays(6)->toDateString();
        $rows = Liquidation::where('seller_id', $sellerId)
            ->whereBetween('date', [$from, $to])->orderBy('date')->get();
        $this->line('');
        $this->line("-- {$label} ({$from} .. {$to}) --");
        $this->table(
            ['fecha', 'estado', 'caja inicial', 'recaudo', 'real a entregar'],
            $rows->map(fn ($l) => [
                $l->date->toDateString(),
                $l->status,
                number_format((float) $l->initial_cash, 2),
                number_format((float) $l->total_collected, 2),
                number_format((float) $l->real_to_deliver, 2),
            ])->all()
        );
    }
}
