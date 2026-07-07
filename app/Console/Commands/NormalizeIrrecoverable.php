<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\Client;
use App\Models\Payment;

/**
 * Normalización histórica del irrecuperable en la caja.
 *
 * Contexto: la regla es "el irrecuperable NO se resta del valor a entregar".
 * El motor actual ya cumple. Pero N liquidaciones VIEJAS lo restaron (fórmula
 * divergente), y ese error viaja por la cadena de caja hasta hoy.
 *
 * Este comando DETECTA esas liquidaciones, atribuye el monto a los créditos
 * irrecuperables concretos, y SOLO propone reversar los créditos que SIGUEN
 * siendo incobrables (si recuperó y pagó, el pago ya compensó → no se reversa).
 *
 * Por defecto es DRY-RUN (no toca nada). --apply queda deshabilitado hasta
 * definir el mecanismo (congelar approved + asiento) con el equipo.
 */
class NormalizeIrrecoverable extends Command
{
    protected $signature = 'liquidations:normalize-irrecoverable {--apply} {--json=}';
    protected $description = 'Detecta y reporta (dry-run) las liquidaciones que restaron mal el irrecuperable y el monto a reversar por vendedor.';

    public function handle()
    {
        $this->info('== Normalización del irrecuperable — DRY-RUN (solo lectura) ==');
        $this->newLine();

        // 1) Liquidaciones con irrecuperable > 0 que SÍ lo restaron (fórmula vieja)
        $withIrr = Liquidation::withTrashed()->where('irrecoverable_credits_amount', '>', 0)
            ->orderBy('seller_id')->orderBy('date')->get();

        $report = [];   // seller_id => ['name'=>, 'days'=>[], 'reversible'=>, 'review'=>]
        $grandReversible = 0.0;
        $grandReview = 0.0;

        foreach ($withIrr as $L) {
            $sin = $L->initial_cash + $L->total_collected + $L->total_income + $L->poliza + $L->base_delivered
                 - $L->total_expenses - $L->new_credits - ($L->renewal_disbursed_total ?? 0);
            $restoMal = abs($L->real_to_deliver - ($sin - $L->irrecoverable_credits_amount)) < 0.5;
            if (!$restoMal) {
                continue; // NO restó → ya está bien, se ignora
            }

            $sid = $L->seller_id;
            $date = substr($L->date, 0, 10);
            $irr = (float) $L->irrecoverable_credits_amount;

            if (!isset($report[$sid])) {
                $report[$sid] = [
                    'name' => optional(optional(Seller::find($sid))->user)->name ?? "seller $sid",
                    'days' => [], 'reversible' => 0.0, 'review' => 0.0,
                ];
            }

            // 2) Atribuir el irrec a créditos concretos.
            //    a) Traza directa: updated_at = ese día y SIGUE irrecuperable.
            $traced = DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->where('credits.seller_id', $sid)
                ->where('credits.status', 'Cartera Irrecuperable')
                ->whereDate('credits.updated_at', $date)
                ->where('installments.status', 'Pendiente')
                ->groupBy('credits.id', 'clients.name')
                ->select('credits.id as cid', 'clients.name as cli', DB::raw('SUM(installments.quota_amount) as pend'))
                ->get();

            $credits = [];
            $tracedSum = 0.0;
            foreach ($traced as $t) {
                $credits[] = ['cid' => $t->cid, 'cli' => $t->cli, 'pend' => (float) $t->pend, 'conf' => 'confirmado'];
                $tracedSum += (float) $t->pend;
            }

            $residual = round($irr - $tracedSum, 2);
            $dayStatus = 'reversible';

            // b) Forense: si queda residual, buscar UN crédito del vendedor que
            //    SIGA irrecuperable con pendiente == residual (recuperado => se excluye).
            if ($residual > 0.01) {
                $cand = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->join('clients', 'credits.client_id', '=', 'clients.id')
                    ->where('credits.seller_id', $sid)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->where('installments.status', 'Pendiente')
                    ->groupBy('credits.id', 'clients.name')
                    ->havingRaw('ABS(SUM(installments.quota_amount) - ?) < 0.01', [$residual])
                    ->select('credits.id as cid', 'clients.name as cli', DB::raw('SUM(installments.quota_amount) as pend'))
                    ->get();

                if ($cand->count() === 1) {
                    $c = $cand->first();
                    $credits[] = ['cid' => $c->cid, 'cli' => $c->cli, 'pend' => (float) $c->pend, 'conf' => 'forense-alta'];
                    $residual = 0.0;
                } else {
                    // No se puede atribuir con certeza (recuperado o ambiguo) → NO reversar, marcar review.
                    $dayStatus = 'review';
                }
            }

            $amount = $dayStatus === 'reversible' ? $irr : $residual;
            if ($dayStatus === 'reversible') {
                $report[$sid]['reversible'] += $irr;
                $grandReversible += $irr;
            } else {
                $report[$sid]['review'] += $residual;
                $grandReview += $residual;
            }

            $report[$sid]['days'][] = [
                'date' => $date, 'liq' => $L->id, 'irr' => $irr,
                'status' => $dayStatus, 'credits' => $credits, 'residual' => $residual,
            ];
        }

        // 3) Imprimir + enriquecer con pagado/pendiente por crédito
        foreach ($report as $sid => $r) {
            $this->newLine();
            $this->line("<fg=cyan>█ {$r['name']}  (seller {$sid})</>");
            foreach ($r['days'] as $d) {
                $tag = $d['status'] === 'reversible' ? '<fg=green>REVERSAR</>' : '<fg=yellow>REVISAR</>';
                $this->line("  {$d['date']}  liq #{$d['liq']}  irrec restado \$" . number_format($d['irr'], 2) . "  → $tag");
                foreach ($d['credits'] as $c) {
                    $paid = (float) Payment::where('credit_id', $c['cid'])->whereNull('deleted_at')->sum('amount');
                    $this->line("      credito #{$c['cid']}  {$c['cli']}  | pagado \$" . number_format($paid, 2)
                        . " (ya en liq. viejas)  | pendiente \$" . number_format($c['pend'], 2)
                        . "  [{$c['conf']}]");
                }
                if ($d['status'] === 'review' && $d['residual'] > 0.01) {
                    $this->line("      <fg=yellow>⚠ \$" . number_format($d['residual'], 2) . " no atribuible (recuperado/ambiguo) → NO se reversa</>");
                }
            }
            $this->line("  <fg=green>Reversable: \$" . number_format($r['reversible'], 2) . "</>"
                . ($r['review'] > 0 ? "   <fg=yellow>A revisar: \$" . number_format($r['review'], 2) . "</>" : ""));
        }

        $this->newLine();
        $this->info('──────────────────────────────────────────────');
        $this->line("Vendedores afectados: " . count($report));
        $this->line("<fg=green>TOTAL A REVERSAR (créditos que siguen irrecuperables): \$" . number_format($grandReversible, 2) . "</>");
        $this->line("<fg=yellow>TOTAL A REVISAR (recuperado/ambiguo, NO se reversa):    \$" . number_format($grandReview, 2) . "</>");
        $this->newLine();

        if ($json = $this->option('json')) {
            file_put_contents($json, json_encode([
                'generated_for' => 'dry-run',
                'grand_reversible' => $grandReversible,
                'grand_review' => $grandReview,
                'sellers' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON escrito en: $json");
        }

        if ($this->option('apply')) {
            $this->error('--apply está DESHABILITADO. Falta definir el mecanismo (congelar approved + asiento). Este comando por ahora solo reporta.');
            return self::FAILURE;
        }

        $this->comment('Dry-run: no se modificó ningún dato.');
        return self::SUCCESS;
    }
}
