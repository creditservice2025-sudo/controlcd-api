<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionCreditAudit;
use App\Models\Collection\CollectionInstallment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Devuelve a la vida las cuotas que una reversa de cobro dio de baja.
 *
 * Por qué existe: hasta este cambio, reversar un cobro marcaba la CUOTA con
 * `deleted_at`. Como todas las sumas de saldo filtran por esa columna, el
 * interés del mes desaparecía junto con el cobro deshecho — y ese interés nace
 * con el crédito, se devengó sobre el capital del período y se sigue debiendo
 * aunque el pago se haya deshecho. El crédito quedaba vivo y sin nada que
 * cobrar. `deleteInstallment` ya no las borra; este comando repara las que
 * quedaron borradas antes.
 *
 * Alcance deliberado:
 *  - solo cuotas de créditos QUE NO ESTÁN ANULADOS: cuando se anula el crédito
 *    entero la baja de sus cuotas es correcta y no se toca;
 *  - solo cuotas con `history`, que es la marca de que la baja vino de una
 *    reversa de cobro y no de otra cosa;
 *  - los importes cobrados vuelven a cero, porque sus pagos están reversados:
 *    dejarlos daría por amortizado un capital que volvió a deberse.
 *
 * Cada reparación queda auditada en collection_credit_audits.
 *
 * Uso:
 *   php artisan collection:restore-reversed-installments --dry-run
 *   php artisan collection:restore-reversed-installments
 *   php artisan collection:restore-reversed-installments --credit=79
 */
class CollectionRestoreReversedInstallments extends Command
{
    protected $signature = 'collection:restore-reversed-installments
        {--dry-run : Solo mostrar lo que cambiaría, sin escribir}
        {--credit=* : Limitar a estos IDs de crédito}';

    protected $description = 'Revive las cuotas dadas de baja por una reversa de cobro (el interés nace con el crédito)';

    private const CONNECTION = 'collection_pgsql';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $creditIds = array_filter((array) $this->option('credit'));

        // Créditos vivos: si el crédito está anulado, sus cuotas están dadas de
        // baja con razón y quedan como están.
        $vivos = CollectionCredit::query()
            ->whereRaw("LOWER(status) <> 'anulado'")
            ->when($creditIds, fn ($q) => $q->whereIn('id', $creditIds))
            ->pluck('status', 'id');

        if ($vivos->isEmpty()) {
            $this->info('No hay créditos vivos que revisar.');
            return self::SUCCESS;
        }

        $cuotas = CollectionInstallment::query()
            ->whereNotNull('deleted_at')
            ->whereNotNull('history')
            ->whereIn('credit_id', $vivos->keys())
            ->orderBy('credit_id')
            ->orderBy('installment_number')
            ->get();

        if ($cuotas->isEmpty()) {
            $this->info('No hay cuotas para revivir. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[SIMULACIÓN] ' : '') . "Cuotas a revivir: {$cuotas->count()}");
        $this->newLine();

        $filas = [];
        foreach ($cuotas as $cuota) {
            $filas[] = [
                $cuota->credit_id,
                '#' . $cuota->installment_number,
                number_format((float) $cuota->interest_amount, 2),
                number_format((float) ($cuota->principal_paid ?? 0), 2),
                number_format((float) ($cuota->interest_paid ?? 0), 2),
                (string) $cuota->deleted_at,
            ];
        }

        $this->table(
            ['Crédito', 'Cuota', 'Interés que vuelve', 'Capital a limpiar', 'Interés a limpiar', 'Dada de baja'],
            $filas
        );

        if ($dryRun) {
            $this->comment('Simulación: no se escribió nada.');
            return self::SUCCESS;
        }

        $reparadas = 0;
        DB::connection(self::CONNECTION)->transaction(function () use ($cuotas, &$reparadas) {
            foreach ($cuotas as $cuota) {
                $previo = [
                    'status' => $cuota->status,
                    'paid_amount' => (float) $cuota->paid_amount,
                    'principal_paid' => (float) ($cuota->principal_paid ?? 0),
                    'interest_paid' => (float) ($cuota->interest_paid ?? 0),
                    'deleted_at' => (string) $cuota->deleted_at,
                ];

                $cuota->update([
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'deleted_ip' => null,
                    'paid_amount' => 0,
                    'principal_paid' => 0,
                    'interest_paid' => 0,
                    'status' => 'pendiente',
                ]);

                CollectionCreditAudit::query()->create([
                    'company_id' => $cuota->company_id,
                    'credit_id' => $cuota->credit_id,
                    'action' => 'installment_restored',
                    'user_id' => null,
                    'ip_address' => null,
                    'changes' => [
                        'installment_number' => $cuota->installment_number,
                        'motivo' => 'Reversa de cobro que había dado de baja la cuota; el interés se sigue debiendo.',
                        'old' => $previo,
                        'new' => ['status' => 'pendiente', 'deleted_at' => null],
                    ],
                ]);

                $reparadas++;
            }
        });

        $this->newLine();
        $this->info("Listo: {$reparadas} cuota(s) revivida(s).");

        return self::SUCCESS;
    }
}
