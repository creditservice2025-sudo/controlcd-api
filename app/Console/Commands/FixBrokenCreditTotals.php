<?php

namespace App\Console\Commands;

use App\Models\Credit;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara créditos con total_amount = 0 (huella del bug histórico de
 * `ClientService::createCreditForNewClient` que no inicializaba el campo).
 *
 * Por cada crédito roto:
 *   1. Calcula total_amount = credit_value + (credit_value * total_interest / 100)
 *      y lo compara con SUM(installments.quota_amount). Si ambos cálculos
 *      coinciden, hace el update con confianza. Si difieren, prefiere
 *      SUM(installments.quota_amount) (más fiable porque las cuotas se
 *      generaron con un cálculo real al momento de la creación) y loggea
 *      la divergencia.
 *   2. Llama PaymentService::reapplyPayments para redistribuir pagos
 *      con unapplied_amount > 0 a installments pendientes.
 *   3. Llama recalculateRemainingAndStatus para sincronizar remaining_amount
 *      desde installments y promover a Liquidado si corresponde.
 *
 * Side-effects:
 *   - Genera CSV en storage/app/fix_broken_totals_YYYYMMDD_HHMMSS.csv
 *     con antes/después de cada crédito procesado.
 *   - Loggea WARN en Laravel log si encuentra divergencias serias.
 *
 * Uso:
 *   php artisan credits:fix-broken-totals --dry-run         # solo reporta
 *   php artisan credits:fix-broken-totals --seller-id=23    # filtra por vendedor
 *   php artisan credits:fix-broken-totals --credit-id=1678  # un solo crédito
 *   php artisan credits:fix-broken-totals --limit=100       # primeros N
 *   php artisan credits:fix-broken-totals                   # aplica todo
 */
class FixBrokenCreditTotals extends Command
{
    protected $signature = 'credits:fix-broken-totals
                            {--dry-run : No modifica nada, solo reporta lo que haría}
                            {--seller-id= : Filtra por vendedor}
                            {--credit-id= : Procesa un solo crédito por id}
                            {--limit= : Procesa solo los primeros N créditos}
                            {--chunk=200 : Tamaño del chunk de procesamiento}
                            {--skip-reapply : No reaplica payments, solo arregla total_amount y remaining_amount}';

    protected $description = 'Repara créditos con total_amount = 0 recalculando desde installments y reaplicando payments';

    public function handle(PaymentService $paymentService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sellerId = $this->option('seller-id');
        $creditId = $this->option('credit-id');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $chunk = (int) ($this->option('chunk') ?: 200);
        $skipReapply = (bool) $this->option('skip-reapply');

        $mode = $dryRun ? 'DRY-RUN (no se modifica nada)' : 'APLICAR cambios en BD';
        $this->info("Modo: {$mode}");

        $query = Credit::query()
            ->whereNull('deleted_at')
            ->where('total_amount', 0)
            ->where('credit_value', '>', 0);

        if ($sellerId) {
            $query->where('seller_id', $sellerId);
            $this->line("Filtrado por seller_id={$sellerId}");
        }
        if ($creditId) {
            $query->where('id', $creditId);
            $this->line("Filtrado por credit_id={$creditId}");
        }
        if ($limit) {
            $query->limit($limit);
            $this->line("Limitado a {$limit} crédito(s)");
        }

        $total = $query->count();
        $this->info("Créditos a procesar: {$total}");
        if ($total === 0) {
            $this->info('Nada que hacer.');
            return self::SUCCESS;
        }

        if (!$dryRun && !$creditId && $total > 50) {
            if (!$this->confirm("Vas a modificar {$total} crédito(s). ¿Continuar?", false)) {
                $this->warn('Cancelado por el usuario.');
                return self::SUCCESS;
            }
        }

        $reportPath = storage_path('app/fix_broken_totals_' . ($dryRun ? 'DRYRUN_' : '') . now()->format('Ymd_His') . '.csv');
        $fp = fopen($reportPath, 'w');
        fputcsv($fp, [
            'credit_id',
            'seller_id',
            'credit_value',
            'total_interest',
            'total_amount_before',
            'total_amount_formula',
            'sum_installments_quota',
            'total_amount_after',
            'remaining_before',
            'remaining_after',
            'status_before',
            'status_after',
            'payments_unapplied_before',
            'payments_reapplied_amount',
            'divergence_formula_vs_installments',
            'observation',
        ]);

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $fixed = 0;
        $skipped = 0;
        $errors = 0;
        $totalAmountRecovered = 0.0;
        $totalRemainingRecovered = 0.0;

        $processFn = function ($credits) use (
            $paymentService, $dryRun, $skipReapply, $fp, $bar,
            &$fixed, &$skipped, &$errors, &$totalAmountRecovered, &$totalRemainingRecovered
        ) {
            foreach ($credits as $credit) {
                try {
                    $result = $this->processCredit($credit, $paymentService, $dryRun, $skipReapply);

                    fputcsv($fp, [
                        $result['credit_id'],
                        $result['seller_id'],
                        $result['credit_value'],
                        $result['total_interest'],
                        $result['total_amount_before'],
                        $result['total_amount_formula'],
                        $result['sum_installments_quota'],
                        $result['total_amount_after'],
                        $result['remaining_before'],
                        $result['remaining_after'],
                        $result['status_before'],
                        $result['status_after'],
                        $result['payments_unapplied_before'],
                        $result['payments_reapplied_amount'],
                        $result['divergence_formula_vs_installments'],
                        $result['observation'],
                    ]);

                    if ($result['skipped']) {
                        $skipped++;
                    } else {
                        $fixed++;
                        $totalAmountRecovered += ((float) $result['total_amount_after'] - (float) $result['total_amount_before']);
                        $totalRemainingRecovered += ((float) $result['remaining_after'] - (float) $result['remaining_before']);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::error("credits:fix-broken-totals fallo en credit {$credit->id}: " . $e->getMessage());
                    fputcsv($fp, [
                        $credit->id,
                        $credit->seller_id,
                        $credit->credit_value,
                        $credit->total_interest,
                        $credit->total_amount,
                        '', '', '',
                        $credit->remaining_amount,
                        '',
                        $credit->status,
                        '', '', '', '',
                        'ERROR: ' . $e->getMessage(),
                    ]);
                }
                $bar->advance();
            }
        };

        if ($limit) {
            // chunkById no respeta limit(); iteramos directamente
            $processFn($query->orderBy('id')->get());
        } else {
            $query->orderBy('id')->chunkById($chunk, $processFn);
        }

        $bar->finish();
        fclose($fp);
        $this->newLine(2);

        $this->info("Procesados OK:    {$fixed}");
        $this->info("Saltados:         {$skipped}");
        if ($errors > 0) {
            $this->warn("Con error:        {$errors} (ver laravel.log)");
        }
        $this->newLine();
        $this->info("Total total_amount recuperado:    $" . number_format($totalAmountRecovered, 2));
        $this->info("Total remaining_amount recuperado: $" . number_format($totalRemainingRecovered, 2));
        $this->newLine();
        $this->info('Reporte CSV:');
        $this->line("  {$reportPath}");

        if ($dryRun) {
            $this->newLine();
            $this->warn('Esto fue DRY-RUN. Para aplicar los cambios corré sin --dry-run.');
        } else {
            $this->newLine();
            $this->info('Cambios aplicados. Revisá el CSV para auditoría.');
        }

        return self::SUCCESS;
    }

    /**
     * Procesa un solo crédito. Devuelve metadata para el CSV.
     */
    protected function processCredit(Credit $credit, PaymentService $paymentService, bool $dryRun, bool $skipReapply): array
    {
        $creditValue = (float) $credit->credit_value;
        $interestRate = (float) $credit->total_interest;
        $totalAmountFormula = round($creditValue + ($creditValue * $interestRate / 100), 2);

        $sumQuota = (float) $credit->installments()
            ->whereNull('deleted_at')
            ->sum('quota_amount');
        $sumQuota = round($sumQuota, 2);

        $observation = [];
        $divergence = round($totalAmountFormula - $sumQuota, 2);

        // Preferimos SUM(installments.quota_amount) si las cuotas existen.
        if ($sumQuota > 0) {
            $newTotalAmount = $sumQuota;
            if (abs($divergence) > 0.01) {
                $observation[] = "Divergencia formula({$totalAmountFormula}) vs installments({$sumQuota}) = {$divergence}";
                \Log::warning("credits:fix-broken-totals divergencia en credit {$credit->id}", [
                    'formula' => $totalAmountFormula,
                    'installments_sum' => $sumQuota,
                    'divergence' => $divergence,
                ]);
            }
        } else {
            $newTotalAmount = $totalAmountFormula;
            $observation[] = 'Sin installments — uso solo fórmula';
        }

        $unappliedSum = (float) \App\Models\Payment::where('credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->sum('unapplied_amount');

        $estimatedRemaining = round(max(0, (float) $credit->installments()
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(quota_amount - paid_amount), 0) as pending')
            ->value('pending')), 2);

        $result = [
            'credit_id' => $credit->id,
            'seller_id' => $credit->seller_id,
            'credit_value' => $credit->credit_value,
            'total_interest' => $credit->total_interest,
            'total_amount_before' => $credit->total_amount,
            'total_amount_formula' => $totalAmountFormula,
            'sum_installments_quota' => $sumQuota,
            'total_amount_after' => $newTotalAmount,
            'remaining_before' => $credit->remaining_amount,
            'remaining_after' => $estimatedRemaining,
            'status_before' => $credit->status,
            'status_after' => $credit->status,
            'payments_unapplied_before' => $unappliedSum,
            'payments_reapplied_amount' => 0,
            'divergence_formula_vs_installments' => $divergence,
            'observation' => '',
            'skipped' => false,
        ];

        if ($dryRun) {
            $observation[] = 'DRY-RUN';
            $result['observation'] = implode(' | ', $observation);
            return $result;
        }

        DB::transaction(function () use ($credit, $newTotalAmount, $paymentService, $skipReapply, $unappliedSum, &$result, &$observation) {
            $credit->total_amount = $newTotalAmount;
            $credit->save();

            $reappliedAmount = 0;
            if (!$skipReapply && $unappliedSum > 0.01) {
                $beforeUnapplied = $unappliedSum;
                $paymentService->reapplyPayments($credit->id);
                $afterUnapplied = (float) \App\Models\Payment::where('credit_id', $credit->id)
                    ->whereNull('deleted_at')
                    ->sum('unapplied_amount');
                $reappliedAmount = round($beforeUnapplied - $afterUnapplied, 2);
                if ($reappliedAmount > 0.01) {
                    $observation[] = "Reaplicó \${$reappliedAmount}";
                }
            }

            $credit->refresh();
            $credit->recalculateRemainingAndStatus();
            $credit->refresh();

            $result['remaining_after'] = $credit->remaining_amount;
            $result['status_after'] = $credit->status;
            $result['payments_reapplied_amount'] = $reappliedAmount;
        });

        $result['observation'] = implode(' | ', $observation);
        return $result;
    }
}
