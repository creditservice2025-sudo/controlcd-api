<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Models\Installment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resincroniza `installments.paid_amount` (campo cacheado) con la fuente
 * de verdad real: SUM(payment_installments.applied_amount).
 *
 * Bug detectado en crédito 656 (installment 5055):
 *   paid_amount cached = $20, pero payment_installments aplicado = $80.
 *   Diferencia $60 perdida en la cache. UI muestra "Pagado" pero los
 *   datos contradicen entre sí.
 *
 * Globalmente afecta ~140 installments con $129,990 de divergencia.
 *
 * Por cada installment desincronizado:
 *   1. Actualiza paid_amount = SUM(payment_installments.applied_amount)
 *   2. Recalcula status del installment:
 *        new_paid >= quota_amount  → 'Pagado'
 *        new_paid > 0              → 'Parcial'
 *        new_paid = 0              → mantiene status anterior
 *   3. Después de procesar todos los installments de un crédito,
 *      llama Credit::recalculateRemainingAndStatus() para resincronizar
 *      la cabecera del crédito (remaining_amount + status).
 *
 * Genera CSV en storage/app/fix_installments_paid_YYYYMMDD_HHMMSS.csv
 *
 * Uso:
 *   php artisan installments:fix-paid-amount-cache --dry-run         # solo reporta
 *   php artisan installments:fix-paid-amount-cache --credit-id=656   # un crédito
 *   php artisan installments:fix-paid-amount-cache --seller-id=23    # un vendedor
 *   php artisan installments:fix-paid-amount-cache --limit=100       # primeros N
 *   php artisan installments:fix-paid-amount-cache                   # aplica todo
 */
class FixInstallmentsPaidAmount extends Command
{
    protected $signature = 'installments:fix-paid-amount-cache
                            {--dry-run : No modifica nada, solo reporta lo que haría}
                            {--credit-id= : Procesa solo installments del crédito X}
                            {--installment-id= : Procesa solo un installment específico}
                            {--seller-id= : Filtra por vendedor}
                            {--limit= : Procesa solo los primeros N installments}
                            {--threshold=0.01 : Diferencia mínima en $ para considerar desincronización}
                            {--skip-credit-recalc : No llama recalculateRemainingAndStatus después}';

    protected $description = 'Resincroniza installments.paid_amount desde payment_installments y recalcula status';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $creditId = $this->option('credit-id');
        $installmentId = $this->option('installment-id');
        $sellerId = $this->option('seller-id');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $threshold = (float) ($this->option('threshold') ?: 0.01);
        $skipCreditRecalc = (bool) $this->option('skip-credit-recalc');

        $mode = $dryRun ? 'DRY-RUN (no se modifica nada)' : 'APLICAR cambios en BD';
        $this->info("Modo: {$mode}");

        // Subquery: real_paid_amount por installment desde payment_installments
        $pivotAgg = DB::table('payment_installments')
            ->select('installment_id')
            ->selectRaw('COALESCE(SUM(applied_amount), 0) AS real_paid')
            ->whereNull('deleted_at')
            ->groupBy('installment_id');

        $query = DB::table('installments AS i')
            ->join('credits AS c', 'c.id', '=', 'i.credit_id')
            ->leftJoinSub($pivotAgg, 'pi', fn($j) => $j->on('pi.installment_id', '=', 'i.id'))
            ->whereNull('i.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereRaw('ABS(i.paid_amount - COALESCE(pi.real_paid, 0)) > ?', [$threshold])
            ->select(
                'i.id AS installment_id',
                'i.credit_id',
                'c.seller_id',
                'c.status AS credit_status',
                'i.quota_number',
                'i.quota_amount',
                'i.paid_amount AS paid_cached',
                DB::raw('COALESCE(pi.real_paid, 0) AS paid_real'),
                'i.status AS installment_status',
                'i.due_date'
            );

        if ($creditId) {
            $query->where('i.credit_id', $creditId);
            $this->line("Filtrado por credit_id={$creditId}");
        }
        if ($installmentId) {
            $query->where('i.id', $installmentId);
            $this->line("Filtrado por installment_id={$installmentId}");
        }
        if ($sellerId) {
            $query->where('c.seller_id', $sellerId);
            $this->line("Filtrado por seller_id={$sellerId}");
        }
        if ($limit) {
            $query->limit($limit);
            $this->line("Limitado a {$limit} installment(s)");
        }

        $total = $query->count();
        $this->info("Installments desincronizados a procesar: {$total}");
        if ($total === 0) {
            $this->info('Nada que hacer. Todos los paid_amount están sincronizados con payment_installments.');
            return self::SUCCESS;
        }

        if (!$dryRun && !$installmentId && !$creditId && $total > 20) {
            if (!$this->confirm("Vas a modificar {$total} installment(s). ¿Continuar?", false)) {
                $this->warn('Cancelado por el usuario.');
                return self::SUCCESS;
            }
        }

        $reportPath = storage_path('app/fix_installments_paid_' . ($dryRun ? 'DRYRUN_' : '') . now()->format('Ymd_His') . '.csv');
        $fp = fopen($reportPath, 'w');
        fputcsv($fp, [
            'installment_id',
            'credit_id',
            'seller_id',
            'credit_status',
            'quota_number',
            'quota_amount',
            'paid_cached_before',
            'paid_real_from_pivot',
            'paid_cached_after',
            'diferencia',
            'status_before',
            'status_after',
            'due_date',
            'observation',
        ]);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $fixed = 0;
        $errors = 0;
        $affectedCreditIds = [];
        $totalCacheRecovered = 0.0;

        // Procesamos sin chunk para permitir transacción por crédito al final
        $rows = $query->orderBy('i.credit_id')->orderBy('i.id')->get();

        foreach ($rows as $row) {
            try {
                $result = $this->processInstallment($row, $dryRun);

                fputcsv($fp, [
                    $result['installment_id'],
                    $result['credit_id'],
                    $result['seller_id'],
                    $result['credit_status'],
                    $result['quota_number'],
                    $result['quota_amount'],
                    $result['paid_cached_before'],
                    $result['paid_real_from_pivot'],
                    $result['paid_cached_after'],
                    $result['diferencia'],
                    $result['status_before'],
                    $result['status_after'],
                    $result['due_date'],
                    $result['observation'],
                ]);

                $fixed++;
                $affectedCreditIds[$row->credit_id] = true;
                $totalCacheRecovered += abs((float) $result['diferencia']);
            } catch (\Throwable $e) {
                $errors++;
                \Log::error("installments:fix-paid-amount-cache fallo en installment {$row->installment_id}: " . $e->getMessage());
                fputcsv($fp, [
                    $row->installment_id,
                    $row->credit_id,
                    $row->seller_id,
                    $row->credit_status,
                    $row->quota_number,
                    $row->quota_amount,
                    $row->paid_cached,
                    $row->paid_real,
                    '',
                    '',
                    $row->installment_status,
                    '',
                    $row->due_date,
                    'ERROR: ' . $e->getMessage(),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Recalcular credit headers después de fix de installments
        $creditsRecalculated = 0;
        if (!$dryRun && !$skipCreditRecalc && count($affectedCreditIds) > 0) {
            $this->info('Recalculando ' . count($affectedCreditIds) . ' crédito(s) afectado(s)...');
            $barC = $this->output->createProgressBar(count($affectedCreditIds));
            $barC->start();
            foreach (array_keys($affectedCreditIds) as $cid) {
                try {
                    $credit = Credit::find($cid);
                    if ($credit) {
                        $credit->recalculateRemainingAndStatus();
                        $creditsRecalculated++;
                    }
                } catch (\Throwable $e) {
                    \Log::error("recalc fallo en credit {$cid}: " . $e->getMessage());
                }
                $barC->advance();
            }
            $barC->finish();
            $this->newLine(2);
        }

        fclose($fp);

        $this->info("Installments procesados:          {$fixed}");
        if ($errors > 0) {
            $this->warn("Con error:                        {$errors} (ver laravel.log)");
        }
        $this->info("Créditos afectados:               " . count($affectedCreditIds));
        if (!$dryRun) {
            $this->info("Créditos recalculados (header):   {$creditsRecalculated}");
        }
        $this->info("Total \$ resincronizado en cache:   $" . number_format($totalCacheRecovered, 2));
        $this->newLine();
        $this->info('Reporte CSV:');
        $this->line("  {$reportPath}");

        if ($dryRun) {
            $this->newLine();
            $this->warn('Esto fue DRY-RUN. Revisá el CSV. Para aplicar corré sin --dry-run.');
        } else {
            $this->newLine();
            $this->info('Cambios aplicados. Revisá el CSV para auditoría.');
        }

        return self::SUCCESS;
    }

    /**
     * Procesa un installment desincronizado. Devuelve metadata para el CSV.
     */
    protected function processInstallment($row, bool $dryRun): array
    {
        $paidCached = (float) $row->paid_cached;
        $paidReal = (float) $row->paid_real;
        $quotaAmount = (float) $row->quota_amount;
        $diferencia = round($paidReal - $paidCached, 2);

        // Calcular nuevo status según el paid_amount real
        $statusBefore = $row->installment_status;
        $newStatus = $statusBefore;
        if ($paidReal >= $quotaAmount - 0.001) {
            $newStatus = 'Pagado';
        } elseif ($paidReal > 0.001) {
            $newStatus = 'Parcial';
        }
        // Si paidReal = 0 dejamos el status original (Pendiente/Atrasado depende de la fecha,
        // y eso lo maneja otra rutina).

        $observation = [];
        if (abs($diferencia) > 100) {
            $observation[] = "Diferencia grande: \${$diferencia}";
        }
        if ($newStatus !== $statusBefore) {
            $observation[] = "Status: {$statusBefore} → {$newStatus}";
        }
        if ($paidReal > $quotaAmount + 0.001) {
            $observation[] = "ALERTA: paid_real (\${$paidReal}) > quota (\${$quotaAmount})";
        }

        $result = [
            'installment_id' => $row->installment_id,
            'credit_id' => $row->credit_id,
            'seller_id' => $row->seller_id,
            'credit_status' => $row->credit_status,
            'quota_number' => $row->quota_number,
            'quota_amount' => $quotaAmount,
            'paid_cached_before' => $paidCached,
            'paid_real_from_pivot' => $paidReal,
            'paid_cached_after' => $paidReal,
            'diferencia' => $diferencia,
            'status_before' => $statusBefore,
            'status_after' => $newStatus,
            'due_date' => $row->due_date,
            'observation' => '',
        ];

        if ($dryRun) {
            $observation[] = 'DRY-RUN';
            $result['observation'] = implode(' | ', $observation);
            return $result;
        }

        DB::transaction(function () use ($row, $paidReal, $newStatus) {
            DB::table('installments')
                ->where('id', $row->installment_id)
                ->update([
                    'paid_amount' => $paidReal,
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);
        });

        $result['observation'] = implode(' | ', $observation);
        return $result;
    }
}
