<?php

namespace App\Console\Commands;

use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detecta (SOLO LECTURA) huecos y duplicados en las liquidaciones diarias por
 * vendedor. Un "hueco" es un día sin liquidación entre dos días que sí la
 * tienen. Prioriza los huecos que ADEMÁS tienen operaciones (pagos) ese día:
 * ese recaudo quedó huérfano y debe regenerarse con liquidations:regenerate-day.
 *
 * Uso:
 *   php artisan liquidations:scan-gaps                    # todos los vendedores
 *   php artisan liquidations:scan-gaps --seller=202       # uno
 *   php artisan liquidations:scan-gaps --only-with-pagos  # solo huecos con recaudo
 *   php artisan liquidations:scan-gaps --max-gap=15       # ignora saltos enormes (inactividad)
 */
class ScanLiquidationGaps extends Command
{
    protected $signature = 'liquidations:scan-gaps
                            {--seller= : Acotar a un seller_id}
                            {--only-with-pagos : Reportar solo huecos que tienen pagos ese día}
                            {--max-gap=31 : Ignorar saltos mayores a N días (probable inactividad)}';

    protected $description = 'Detecta (solo lectura) huecos y duplicados de liquidaciones diarias por vendedor';

    public function handle(): int
    {
        $sellerFilter = $this->option('seller');
        $onlyWithPagos = (bool) $this->option('only-with-pagos');
        $maxGap = (int) $this->option('max-gap');

        $sellerIds = Liquidation::query()
            ->when($sellerFilter, fn ($q) => $q->where('seller_id', $sellerFilter))
            ->whereNull('deleted_at')
            ->select('seller_id')->distinct()->pluck('seller_id');

        $gaps = [];
        $dups = [];

        foreach ($sellerIds as $sid) {
            $dates = Liquidation::where('seller_id', $sid)->whereNull('deleted_at')
                ->orderBy('date')->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString());

            // Duplicados: más de una liquidación viva para la misma fecha.
            foreach ($dates->countBy() as $d => $c) {
                if ($c > 1) {
                    $dups[] = ['seller' => $sid, 'date' => $d, 'count' => $c];
                }
            }

            // Huecos entre fechas consecutivas.
            $uniq = $dates->unique()->values();
            for ($i = 1; $i < $uniq->count(); $i++) {
                $prev = Carbon::parse($uniq[$i - 1]);
                $cur = Carbon::parse($uniq[$i]);
                $diff = $prev->diffInDays($cur);
                if ($diff <= 1 || $diff > $maxGap) {
                    continue; // contiguo, o salto enorme (inactividad) -> ignorar
                }
                for ($k = 1; $k < $diff; $k++) {
                    $missing = $prev->copy()->addDays($k)->toDateString();
                    $pagos = $this->paymentsOnDay($sid, $missing);
                    if ($onlyWithPagos && $pagos['n'] == 0) {
                        continue;
                    }
                    $gaps[] = [
                        'seller' => $sid,
                        'date' => $missing,
                        'n_pagos' => $pagos['n'],
                        'recaudo' => $pagos['sum'],
                    ];
                }
            }
        }

        // --- Reporte de huecos ---
        $this->line('');
        $this->line('=== Huecos de liquidación (usar liquidations:regenerate-day) ===');
        if (empty($gaps)) {
            $this->info('Ninguno.');
        } else {
            usort($gaps, fn ($a, $b) => $b['recaudo'] <=> $a['recaudo']);
            $this->table(
                ['seller', 'fecha faltante', '# pagos', 'recaudo huérfano'],
                array_map(fn ($g) => [
                    $g['seller'], $g['date'], $g['n_pagos'], number_format($g['recaudo'], 2),
                ], $gaps)
            );
        }

        // --- Reporte de duplicados ---
        $this->line('');
        $this->line('=== Fechas con liquidación duplicada ===');
        if (empty($dups)) {
            $this->info('Ninguno.');
        } else {
            $this->table(
                ['seller', 'fecha', '# liquidaciones'],
                array_map(fn ($d) => [$d['seller'], $d['date'], $d['count']], $dups)
            );
        }

        $withPagos = count(array_filter($gaps, fn ($g) => $g['n_pagos'] > 0));
        $this->line('');
        $this->info('Resumen: ' . count($gaps) . " hueco(s) ({$withPagos} con recaudo), " . count($dups) . ' fecha(s) duplicada(s).');

        return self::SUCCESS;
    }

    /** Pagos vivos con business_date en el día dado, para el vendedor. */
    private function paymentsOnDay($sellerId, string $date): array
    {
        $r = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('payments.business_date', $date)
            ->whereNull('payments.deleted_at')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(payments.amount),0) s')
            ->first();

        return ['n' => (int) $r->n, 'sum' => (float) $r->s];
    }
}
