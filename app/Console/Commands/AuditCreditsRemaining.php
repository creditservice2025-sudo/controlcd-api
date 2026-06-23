<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría READ-ONLY de créditos con remaining_amount o status posiblemente
 * desincronizados. NO MODIFICA NADA: solo genera un CSV en storage/app/
 * con todas las divergencias detectadas para que el admin valide antes de
 * decidir si aplicar el fix.
 *
 * Cruza 3 fuentes de verdad para cada crédito:
 *   1. credits.remaining_amount      → lo que dice la columna hoy
 *   2. SUM(installments pendientes)  → calculado desde cuotas (quota - paid)
 *   3. total_amount - SUM(payments)  → calculado desde pagos recibidos
 *
 * Si las 3 coinciden → no aparece en el reporte.
 * Si alguna diverge → fila en el CSV con todas las columnas para análisis.
 *
 * Uso:
 *   php artisan credits:audit-remaining
 *   php artisan credits:audit-remaining --seller-id=156
 *   php artisan credits:audit-remaining --only-status-mismatch
 */
class AuditCreditsRemaining extends Command
{
    protected $signature = 'credits:audit-remaining
                            {--seller-id= : Filtrar por un vendedor específico}
                            {--only-status-mismatch : Solo créditos cuyo status debería cambiar}
                            {--threshold=0.01 : Diferencia mínima en $ para considerar discrepancia}';

    protected $description = 'Audita créditos desincronizados (READ-ONLY) y genera CSV de validación';

    public function handle(): int
    {
        $sellerId = $this->option('seller-id');
        $onlyStatusMismatch = (bool) $this->option('only-status-mismatch');
        $threshold = (float) $this->option('threshold');

        $this->info("Auditando créditos (READ-ONLY, no se modifica nada)...");

        // Query base: todos los créditos no soft-deleted, opcionalmente
        // filtrados por seller. JOIN a clients y sellers para metadata
        // del reporte. JOIN a users para el nombre del cobrador.
        $q = DB::table('credits as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('sellers as s', 's.id', '=', 'c.seller_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->whereNull('c.deleted_at')
            ->select(
                'c.id as credit_id',
                'c.client_id',
                'c.seller_id',
                'c.credit_value',
                'c.total_amount',
                'c.remaining_amount as remaining_db',
                'c.status as status_db',
                'c.created_at as credit_created_at',
                'cl.name as client_name',
                'cl.dni as client_dni',
                'u.name as seller_name',
            );

        if ($sellerId) {
            $q->where('c.seller_id', $sellerId);
        }

        $total = (clone $q)->count();
        $this->info("Total a revisar: {$total} crédito(s).");

        $reportPath = storage_path('app/credits_audit_' . now()->format('Ymd_His') . '.csv');
        $fp = fopen($reportPath, 'w');
        fputcsv($fp, [
            'seller_id',
            'seller_name',
            'client_id',
            'client_name',
            'client_dni',
            'credit_id',
            'credit_value',
            'total_amount',
            'remaining_db',
            'remaining_calc_cuotas',
            'remaining_calc_payments',
            'diff_db_vs_cuotas',
            'diff_db_vs_payments',
            'diff_cuotas_vs_payments',
            'status_db',
            'status_proposed',
            'installments_total',
            'installments_paid',
            'sum_payments',
            'sum_unapplied',
            'credit_created_at',
            'observation',
        ]);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $diverged = 0;
        $statusChanges = 0;

        $q->orderBy('c.seller_id')->orderBy('c.id')->chunk(500, function ($credits) use ($fp, $bar, &$diverged, &$statusChanges, $threshold, $onlyStatusMismatch) {
            $creditIds = collect($credits)->pluck('credit_id')->all();

            // Stats de cuotas en bloque (1 query por chunk en lugar de N+1)
            $instStats = DB::table('installments')
                ->whereIn('credit_id', $creditIds)
                ->whereNull('deleted_at')
                ->groupBy('credit_id')
                ->select('credit_id')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'Pagado' THEN 1 ELSE 0 END) as paid_count")
                ->selectRaw('COALESCE(SUM(quota_amount - paid_amount), 0) as remaining_calc')
                ->get()
                ->keyBy('credit_id');

            // Stats de pagos en bloque
            $paymentStats = DB::table('payments')
                ->whereIn('credit_id', $creditIds)
                ->whereNull('deleted_at')
                ->groupBy('credit_id')
                ->select('credit_id')
                ->selectRaw('COALESCE(SUM(amount), 0) as sum_amount')
                ->selectRaw('COALESCE(SUM(unapplied_amount), 0) as sum_unapplied')
                ->get()
                ->keyBy('credit_id');

            foreach ($credits as $c) {
                $inst = $instStats[$c->credit_id] ?? null;
                $pay = $paymentStats[$c->credit_id] ?? null;

                $remainingDb = round((float) $c->remaining_db, 2);
                $remainingCalcCuotas = $inst ? round((float) $inst->remaining_calc, 2) : 0;
                $sumPayments = $pay ? round((float) $pay->sum_amount, 2) : 0;
                $sumUnapplied = $pay ? round((float) $pay->sum_unapplied, 2) : 0;
                $totalAmount = round((float) $c->total_amount, 2);
                $remainingCalcPayments = round(max(0, $totalAmount - $sumPayments), 2);

                $diffDbCuotas = round($remainingDb - $remainingCalcCuotas, 2);
                $diffDbPayments = round($remainingDb - $remainingCalcPayments, 2);
                $diffCuotasPayments = round($remainingCalcCuotas - $remainingCalcPayments, 2);

                // Determinar status propuesto siguiendo la misma regla del
                // método Credit::recalculateRemainingAndStatus.
                $statusProposed = $c->status_db;
                $terminal = ['Renovado', 'Cartera Irrecuperable', 'Inactivo', 'Unificado'];
                $hasUnpaid = $inst ? ((int) $inst->total - (int) $inst->paid_count) > 0 : true;
                if (!in_array($c->status_db, $terminal, true)) {
                    if (!$hasUnpaid && $remainingCalcCuotas <= 0.001) {
                        $statusProposed = 'Liquidado';
                    } elseif ($c->status_db === 'Liquidado' && ($hasUnpaid || $remainingCalcCuotas > 0.001)) {
                        $statusProposed = 'Vigente';
                    }
                }

                $hasDivergence =
                    abs($diffDbCuotas) >= $threshold
                    || abs($diffDbPayments) >= $threshold
                    || abs($diffCuotasPayments) >= $threshold;
                $statusChange = ($statusProposed !== $c->status_db);

                if ($onlyStatusMismatch) {
                    if (!$statusChange) {
                        $bar->advance();
                        continue;
                    }
                } elseif (!$hasDivergence && !$statusChange) {
                    $bar->advance();
                    continue;
                }

                $observation = [];
                if (abs($diffDbCuotas) >= $threshold) {
                    $observation[] = "remaining_db != cuotas (diff={$diffDbCuotas})";
                }
                if (abs($diffDbPayments) >= $threshold) {
                    $observation[] = "remaining_db != payments (diff={$diffDbPayments})";
                }
                if (abs($diffCuotasPayments) >= $threshold) {
                    $observation[] = "cuotas != payments (diff={$diffCuotasPayments})";
                }
                if ($statusChange) {
                    $observation[] = "status: {$c->status_db} -> {$statusProposed}";
                }

                fputcsv($fp, [
                    $c->seller_id,
                    $c->seller_name,
                    $c->client_id,
                    $c->client_name,
                    $c->client_dni,
                    $c->credit_id,
                    $c->credit_value,
                    $totalAmount,
                    $remainingDb,
                    $remainingCalcCuotas,
                    $remainingCalcPayments,
                    $diffDbCuotas,
                    $diffDbPayments,
                    $diffCuotasPayments,
                    $c->status_db,
                    $statusProposed,
                    $inst->total ?? 0,
                    $inst->paid_count ?? 0,
                    $sumPayments,
                    $sumUnapplied,
                    $c->credit_created_at,
                    implode(' | ', $observation),
                ]);

                $diverged++;
                if ($statusChange) $statusChanges++;
                $bar->advance();
            }
        });

        $bar->finish();
        fclose($fp);
        $this->newLine(2);

        $this->info("Créditos con divergencia detectada: {$diverged}");
        $this->info("Créditos con cambio de status sugerido: {$statusChanges}");
        $this->info("Reporte guardado en:");
        $this->line("  {$reportPath}");
        $this->newLine();
        $this->warn('Este comando NO modificó la base de datos. Es solo lectura.');
        $this->warn('Revisá el CSV con tu equipo y luego, si lo aprobás, corré:');
        $this->line('  php artisan credits:recalculate-remaining --dry-run    # simulación');
        $this->line('  php artisan credits:recalculate-remaining               # aplicar');

        return self::SUCCESS;
    }
}
