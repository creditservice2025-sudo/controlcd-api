<?php

namespace App\Console\Commands;

use App\Models\Credit;
use Illuminate\Console\Command;

/**
 * One-shot fix para resincronizar remaining_amount y status de los créditos
 * que quedaron desincronizados con la realidad de sus cuotas/pagos.
 *
 * Causas históricas conocidas:
 *  - PaymentService::create usaba `remaining_amount -= amount` (delta), que
 *    se desincronizaba si había unapplied_amount o paths no contemplados.
 *  - PaymentService::reapplyPayments NO actualizaba remaining_amount, así
 *    que abonos pendientes que cubrían el crédito nunca lo liquidaban.
 *  - PaymentService::deletePaymentInstallment no tocaba el crédito al
 *    revertir un movimiento.
 *
 * Después del fix en el modelo Credit::recalculateRemainingAndStatus(),
 * este comando re-aplica esa lógica a los créditos ya existentes para
 * dejarlos consistentes.
 */
class RecalculateCreditsRemaining extends Command
{
    protected $signature = 'credits:recalculate-remaining
                            {--credit-id= : Recalcular un crédito específico por ID}
                            {--dry-run : Mostrar lo que cambiaría sin guardar}';

    protected $description = 'Resincroniza credits.remaining_amount y status desde la verdad (cuotas)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $creditId = $this->option('credit-id');

        $query = Credit::query()->whereNull('deleted_at');
        if ($creditId) {
            $query->where('id', $creditId);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('No hay créditos para procesar.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Procesando {$total} crédito(s)...");

        $changedRemaining = 0;
        $changedStatus = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunkById(200, function ($credits) use (&$changedRemaining, &$changedStatus, $dryRun, $bar) {
            foreach ($credits as $credit) {
                $oldRemaining = (float) $credit->remaining_amount;
                $oldStatus = $credit->status;

                if ($dryRun) {
                    // Simulación: calculamos sin persistir.
                    $remaining = (float) $credit->installments()
                        ->whereNull('deleted_at')
                        ->selectRaw('COALESCE(SUM(quota_amount - paid_amount), 0) as pending')
                        ->value('pending');
                    $remaining = max(0, round($remaining, 2));

                    $hasUnpaid = $credit->installments()->where('status', '<>', 'Pagado')->exists();
                    $newStatus = $oldStatus;
                    $terminal = ['Renovado', 'Cartera Irrecuperable', 'Inactivo', 'Unificado'];
                    if (!in_array($oldStatus, $terminal, true)) {
                        if (!$hasUnpaid && $remaining <= 0.001) {
                            $newStatus = 'Liquidado';
                        } elseif ($oldStatus === 'Liquidado') {
                            $newStatus = 'Vigente';
                        }
                    }

                    if (abs($remaining - $oldRemaining) > 0.01) $changedRemaining++;
                    if ($newStatus !== $oldStatus) $changedStatus++;
                } else {
                    $credit->recalculateRemainingAndStatus();
                    $credit->refresh();
                    if (abs((float) $credit->remaining_amount - $oldRemaining) > 0.01) $changedRemaining++;
                    if ($credit->status !== $oldStatus) $changedStatus++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Créditos con remaining_amount ajustado: {$changedRemaining}");
        $this->info("Créditos con status ajustado: {$changedStatus}");
        if ($dryRun) {
            $this->warn('Modo DRY-RUN: nada se guardó. Volvé a correr sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }
}
