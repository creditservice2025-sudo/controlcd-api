<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detecta (SOLO LECTURA) créditos con montos incoherentes, para monitoreo.
 * No modifica nada. Reporta dos clases de anomalía:
 *
 *  1) total_amount desincronizado: total_amount ≠ credit_value·(1+interés/100)
 *     por un factor de escala (ej: #5435). Se corrige con `credits:fix-inflated`.
 *  2) Pagos > deuda: SUM(pagos vivos) excede la deuda correcta del crédito
 *     (typo en el monto del pago). Requiere revisión caso por caso.
 *
 * Uso:
 *   php artisan credits:scan-anomalies                 # umbral material por defecto (1000)
 *   php artisan credits:scan-anomalies --min=0         # incluir hasta centavos
 *   php artisan credits:scan-anomalies --seller=45     # acotar a un vendedor
 */
class ScanCreditAnomalies extends Command
{
    protected $signature = 'credits:scan-anomalies
                            {--min=1000 : Exceso mínimo (en moneda) para reportar}
                            {--seller= : Acotar a un seller_id}';

    protected $description = 'Detecta (solo lectura) créditos con total_amount desincronizado o con pagos que exceden la deuda';

    public function handle(): int
    {
        $min = (float) $this->option('min');
        $sellerId = $this->option('seller');

        // Subconsulta base: deuda esperada y pagos vivos por crédito.
        $sub = DB::table('credits')
            ->leftJoin(DB::raw('(select credit_id, sum(amount) pa, count(*) n from payments where deleted_at is null group by credit_id) p'), 'p.credit_id', '=', 'credits.id')
            ->whereNull('credits.deleted_at')
            ->when($sellerId, fn ($q) => $q->where('credits.seller_id', $sellerId))
            ->selectRaw('credits.id, credits.seller_id, credits.status, credits.credit_value,
                         credits.total_amount,
                         (credits.credit_value*(1+credits.total_interest/100)) esperado,
                         coalesce(p.pa,0) paid, coalesce(p.n,0) npays');

        // --- Clase 1: total_amount desincronizado ---
        $desync = DB::query()->fromSub($sub, 'x')
            ->whereRaw('abs(total_amount - esperado) > ?', [$min])
            ->orderByRaw('abs(total_amount - esperado) desc')
            ->get();

        $this->line('');
        $this->line('=== Clase 1: total_amount desincronizado (usar credits:fix-inflated) ===');
        if ($desync->isEmpty()) {
            $this->info('Ninguno.');
        } else {
            $this->table(
                ['crédito', 'seller', 'estado', 'credit_value', 'total_amount', 'esperado', 'desvío'],
                $desync->map(fn ($r) => [
                    '#' . $r->id, $r->seller_id, $r->status,
                    number_format((float) $r->credit_value, 2),
                    number_format((float) $r->total_amount, 2),
                    number_format((float) $r->esperado, 2),
                    number_format((float) $r->total_amount - (float) $r->esperado, 2),
                ])->all()
            );
        }

        // --- Clase 2: pagos > deuda (con total_amount correcto) ---
        $overpaid = DB::query()->fromSub($sub, 'x')
            ->whereRaw('paid > esperado + ?', [$min])
            ->whereRaw('abs(total_amount - esperado) <= ?', [$min])
            ->orderByRaw('(paid - esperado) desc')
            ->get();

        $this->line('');
        $this->line('=== Clase 2: pagos > deuda (revisión caso por caso) ===');
        if ($overpaid->isEmpty()) {
            $this->info('Ninguno.');
        } else {
            $this->table(
                ['crédito', 'seller', 'estado', 'deuda', 'pagado', 'exceso', '#pagos'],
                $overpaid->map(fn ($r) => [
                    '#' . $r->id, $r->seller_id, $r->status,
                    number_format((float) $r->esperado, 2),
                    number_format((float) $r->paid, 2),
                    number_format((float) $r->paid - (float) $r->esperado, 2),
                    $r->npays,
                ])->all()
            );
        }

        $this->line('');
        $this->info("Resumen: {$desync->count()} con total_amount desincronizado, {$overpaid->count()} con pagos > deuda (umbral {$min}).");

        return self::SUCCESS;
    }
}
