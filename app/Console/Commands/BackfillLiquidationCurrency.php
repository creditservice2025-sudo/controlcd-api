<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige la ETIQUETA de moneda de liquidaciones viejas que quedaron en 'PEN'
 * por el fallback (LiquidationService: $country?->currency ?? 'PEN') cuando el
 * país todavía no tenía la moneda cargada.
 *
 * SEGURIDAD — este comando SOLO cambia el campo `currency` (3 letras). NO toca:
 *   montos, real_to_deliver, initial_cash, status, ni recalcula nada.
 * Los importes siempre estuvieron en la moneda real; solo la etiqueta estaba mal.
 * Usa query builder (no Eloquent) para NO disparar observers ni tocar updated_at.
 *
 * Dry-run por defecto. --apply para aplicar. --country=Colombia para acotar.
 */
class BackfillLiquidationCurrency extends Command
{
    protected $signature = 'liquidations:backfill-currency {--apply} {--force} {--country=}';
    protected $description = 'Corrige la moneda (etiqueta) de liquidaciones que quedaron en PEN por fallback. Solo el campo currency. Dry-run por defecto.';

    public function handle()
    {
        $apply = (bool) $this->option('apply');
        $countryFilter = $this->option('country');

        $this->info('== Backfill de moneda de liquidaciones — ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' ==');
        $this->warn('SOLO cambia el campo `currency`. No toca montos, rtd, status ni recalcula.');
        $this->newLine();

        // Liquidaciones cuya etiqueta NO coincide con la moneda del país del vendedor.
        $base = DB::table('liquidations')
            ->join('sellers', 'liquidations.seller_id', '=', 'sellers.id')
            ->join('cities', 'sellers.city_id', '=', 'cities.id')
            ->join('countries', 'cities.country_id', '=', 'countries.id')
            ->whereNotNull('countries.currency')
            ->where('countries.currency', '<>', '')
            ->where(function ($q) {
                $q->whereColumn('liquidations.currency', '<>', 'countries.currency')
                    ->orWhereNull('liquidations.currency');
            });

        if ($countryFilter) {
            $base->where('countries.name', $countryFilter);
        }

        // Reporte agrupado.
        $groups = (clone $base)
            ->selectRaw('countries.name pais, countries.currency correcta, liquidations.currency actual, count(*) c')
            ->groupBy('pais', 'correcta', 'actual')
            ->orderBy('pais')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No hay liquidaciones con moneda desalineada. Nada que hacer.');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($groups as $g) {
            $total += $g->c;
            $this->line(sprintf(
                '  %-12s  %s → %s   (%d liquidaciones)',
                $g->pais,
                $g->actual ?: 'NULL',
                $g->correcta,
                $g->c
            ));
        }
        $this->newLine();
        $this->line("<fg=green>TOTAL a corregir: {$total} liquidaciones</>");

        // Muestra de IDs (para auditar antes de aplicar).
        $sample = (clone $base)
            ->select('liquidations.id', 'liquidations.date', 'liquidations.currency as actual', 'countries.currency as correcta')
            ->orderBy('liquidations.id')->limit(5)->get();
        $this->line('  muestra: ' . $sample->map(fn ($s) => "#{$s->id}({$s->actual}→{$s->correcta})")->implode('  '));
        $this->newLine();

        if (!$apply) {
            $this->comment('Dry-run: no se modificó nada. Agregá --apply para aplicar.');
            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm("Vas a corregir la moneda de {$total} liquidaciones (solo la etiqueta). ¿Continuar?")) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        // Aplicar: un UPDATE por moneda destino, sobre los IDs exactos.
        // Query builder: NO dispara observers, NO toca updated_at, NO recalcula.
        $changed = 0;
        DB::transaction(function () use ($base, &$changed) {
            $targets = (clone $base)
                ->select('liquidations.id', 'countries.currency as correcta')
                ->get()
                ->groupBy('correcta');

            foreach ($targets as $correcta => $rows) {
                $ids = $rows->pluck('id')->all();
                foreach (array_chunk($ids, 1000) as $chunk) {
                    $changed += DB::table('liquidations')
                        ->whereIn('id', $chunk)
                        ->update(['currency' => $correcta]);
                }
            }
        });

        $this->newLine();
        $this->info("APLICADO: {$changed} liquidaciones con la moneda corregida (solo la etiqueta).");
        return self::SUCCESS;
    }
}
