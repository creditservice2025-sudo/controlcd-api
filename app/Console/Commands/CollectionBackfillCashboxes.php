<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCashbox;
use App\Models\Collection\CollectionDailyRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed + backfill de cajas (multi-caja) para la bitácora de Collection.
 *
 * Por cada empresa que tenga registros diarios (o cajas ya creadas) garantiza
 * una "Caja principal" marcada como default, y asigna todos los movimientos
 * huérfanos (cashbox_id IS NULL) a esa caja. Idempotente: se puede reejecutar.
 *
 * Uso:
 *   php artisan collection:backfill-cashboxes
 *   php artisan collection:backfill-cashboxes --dry-run
 */
class CollectionBackfillCashboxes extends Command
{
    protected $signature = 'collection:backfill-cashboxes {--dry-run : Solo mostrar, sin escribir}';

    protected $description = 'Crea la Caja principal por empresa y asigna los registros diarios existentes';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Empresas con registros diarios: derivamos moneda/país predominante.
        $companies = CollectionDailyRecord::query()
            ->selectRaw('company_id, max(currency) as currency, max(country_code) as country_code')
            ->groupBy('company_id')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('No hay registros diarios; nada que hacer.');
            return self::SUCCESS;
        }

        $created = 0;
        $assigned = 0;

        foreach ($companies as $row) {
            $companyId = (int) $row->company_id;

            // 1) Garantizar una caja default para la empresa.
            $default = CollectionCashbox::where('company_id', $companyId)
                ->where('is_default', true)
                ->first();

            if (!$default) {
                // Si ya existe alguna caja no-default, promovemos la primera;
                // si no hay ninguna, creamos "Caja principal".
                $default = CollectionCashbox::where('company_id', $companyId)
                    ->orderBy('id')
                    ->first();

                if ($default) {
                    if (!$dry) {
                        $default->update(['is_default' => true]);
                    }
                    $this->line("Empresa {$companyId}: caja #{$default->id} promovida a default.");
                } else {
                    $this->line("Empresa {$companyId}: crear 'Caja principal' (COP={$row->currency}).");
                    if (!$dry) {
                        $default = CollectionCashbox::create([
                            'company_id'      => $companyId,
                            'name'            => 'Caja principal',
                            'currency'        => $row->currency ?: 'COP',
                            'country_code'    => $row->country_code,
                            'opening_balance' => 0,
                            'is_default'      => true,
                            'active'          => true,
                            'sort_order'      => 0,
                        ]);
                    }
                    $created++;
                }
            }

            // 2) Asignar movimientos huérfanos a la caja default.
            $orphans = CollectionDailyRecord::withTrashed()
                ->where('company_id', $companyId)
                ->whereNull('cashbox_id')
                ->count();

            if ($orphans > 0) {
                $this->line("Empresa {$companyId}: {$orphans} movimientos sin caja -> default.");
                if (!$dry && $default) {
                    $n = CollectionDailyRecord::withTrashed()
                        ->where('company_id', $companyId)
                        ->whereNull('cashbox_id')
                        ->update(['cashbox_id' => $default->id]);
                    $assigned += $n;
                }
            }
        }

        $this->info($dry
            ? "[dry-run] cajas a crear: {$created}"
            : "Listo. Cajas creadas: {$created}. Movimientos asignados: {$assigned}.");

        return self::SUCCESS;
    }
}
