<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\Income;
use App\Models\Payment;
use App\Services\LiquidationService;
use App\Helpers\TimezoneHelper;
use Carbon\Carbon;

/**
 * Normalización histórica del irrecuperable en la caja.
 *
 * Regla: "el irrecuperable NO se resta del valor a entregar". El motor actual
 * ya cumple, pero N liquidaciones VIEJAS lo restaron (fórmula divergente) y ese
 * error viaja por la cadena hasta hoy.
 *
 * Reversa = un INGRESO documentado por crédito, en el DÍA ABIERTO del vendedor,
 * por el monto pendiente que se restó mal. Solo créditos que SIGUEN en Cartera
 * Irrecuperable (si recuperó y pagó, el pago ya compensó). Lo pagado nunca se
 * re-cuenta (quedó en su liquidación cerrada).
 *
 * Requiere que las 'approved' estén congeladas en el recálculo (ya implementado)
 * para que el motor no vuelva a sumar el irrec y se duplique.
 *
 * Por defecto DRY-RUN. Con --apply crea los ingresos (idempotente, transaccional).
 */
class NormalizeIrrecoverable extends Command
{
    protected $signature = 'liquidations:normalize-irrecoverable {--apply} {--force} {--revert} {--json=}';
    protected $description = 'Reversa (via ingreso documentado) el irrecuperable restado indebidamente en liquidaciones viejas. Dry-run por defecto.';

    private const TAG = 'AJUSTE-NORM-IRREC';

    public function handle()
    {
        if ($this->option('revert')) {
            return $this->handleRevert();
        }

        $apply = (bool) $this->option('apply');
        $this->info('== Normalización del irrecuperable — ' . ($apply ? 'APLICAR' : 'DRY-RUN (solo lectura)') . ' ==');
        $this->newLine();

        $liqSvc = app(LiquidationService::class);

        $withIrr = Liquidation::withTrashed()->where('irrecoverable_credits_amount', '>', 0)
            ->orderBy('seller_id')->orderBy('date')->get();

        $report = [];
        $grandReversible = 0.0;
        $grandReview = 0.0;

        foreach ($withIrr as $L) {
            $sin = $L->initial_cash + $L->total_collected + $L->total_income + $L->poliza + $L->base_delivered
                 - $L->total_expenses - $L->new_credits - ($L->renewal_disbursed_total ?? 0);
            if (abs($L->real_to_deliver - ($sin - $L->irrecoverable_credits_amount)) >= 0.5) {
                continue; // no restó → ya está bien
            }

            $sid = $L->seller_id;
            $date = substr($L->date, 0, 10);
            $irr = (float) $L->irrecoverable_credits_amount;

            if (!isset($report[$sid])) {
                $seller = Seller::find($sid);
                $report[$sid] = [
                    'name'    => optional(optional($seller)->user)->name ?? "seller $sid",
                    'user_id' => optional($seller)->user_id,
                    'tz'      => $seller ? TimezoneHelper::getSellerTimezone($seller) : 'America/Lima',
                    'days'    => [], 'reversible' => 0.0, 'review' => 0.0,
                ];
            }

            // Atribuir a créditos que SIGUEN irrecuperables.
            $traced = DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->join('clients', 'credits.client_id', '=', 'clients.id')
                ->where('credits.seller_id', $sid)->where('credits.status', 'Cartera Irrecuperable')
                ->whereDate('credits.updated_at', $date)->where('installments.status', 'Pendiente')
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
            $status = 'reversible';
            if ($residual > 0.01) {
                $cand = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->join('clients', 'credits.client_id', '=', 'clients.id')
                    ->where('credits.seller_id', $sid)->where('credits.status', 'Cartera Irrecuperable')
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
                    $status = 'review';
                }
            }

            if ($status === 'reversible') {
                $report[$sid]['reversible'] += $irr;
                $grandReversible += $irr;
            } else {
                $report[$sid]['review'] += $residual;
                $grandReview += $residual;
            }

            $report[$sid]['days'][] = [
                'date' => $date, 'liq' => $L->id, 'irr' => $irr,
                'status' => $status, 'credits' => $credits, 'residual' => $residual,
            ];
        }

        // ---- Reporte ----
        foreach ($report as $sid => $r) {
            $this->newLine();
            $this->line("<fg=cyan>█ {$r['name']}  (seller {$sid})</>");
            foreach ($r['days'] as $d) {
                $tag = $d['status'] === 'reversible' ? '<fg=green>REVERSAR</>' : '<fg=yellow>REVISAR</>';
                $this->line("  {$d['date']}  liq #{$d['liq']}  irrec \$" . number_format($d['irr'], 2) . "  → $tag");
                foreach ($d['credits'] as $c) {
                    $paid = (float) Payment::where('credit_id', $c['cid'])->whereNull('deleted_at')->sum('amount');
                    $this->line("      credito #{$c['cid']}  {$c['cli']}  | pagado \$" . number_format($paid, 2)
                        . "  | pendiente \$" . number_format($c['pend'], 2) . "  [{$c['conf']}]");
                }
                if ($d['status'] === 'review' && $d['residual'] > 0.01) {
                    $this->line("      <fg=yellow>⚠ \$" . number_format($d['residual'], 2) . " no atribuible → NO se reversa</>");
                }
            }
            $this->line("  <fg=green>Reversable: \$" . number_format($r['reversible'], 2) . "</>"
                . ($r['review'] > 0 ? "   <fg=yellow>A revisar: \$" . number_format($r['review'], 2) . "</>" : ""));
        }

        $this->newLine();
        $this->info('──────────────────────────────────────────────');
        $this->line("Vendedores afectados: " . count($report));
        $this->line("<fg=green>TOTAL A REVERSAR: \$" . number_format($grandReversible, 2) . "</>");
        $this->line("<fg=yellow>TOTAL A REVISAR (no se reversa): \$" . number_format($grandReview, 2) . "</>");
        $this->newLine();

        if ($json = $this->option('json')) {
            file_put_contents($json, json_encode([
                'grand_reversible' => $grandReversible, 'grand_review' => $grandReview, 'sellers' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON escrito en: $json");
        }

        if (!$apply) {
            $this->comment('Dry-run: no se modificó ningún dato. Corré con --apply para aplicar.');
            return self::SUCCESS;
        }

        // ---- APPLY ----
        if (!$this->option('force') && !$this->confirm('Vas a crear ingresos de ajuste en PRODUCCIÓN por $' . number_format($grandReversible, 2) . ' en ' . count($report) . ' vendedores. ¿Continuar?')) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        $created = 0; $skipped = 0; $sellersTouched = 0;
        foreach ($report as $sid => $r) {
            if ($r['reversible'] <= 0 || !$r['user_id']) continue;
            $tz = $r['tz'];
            $now = Carbon::now($tz);
            $today = $now->toDateString();

            DB::transaction(function () use ($sid, $r, $tz, $now, $today, $liqSvc, &$created, &$skipped) {
                // Asegurar día abierto (no aprobado) donde caiga el ajuste.
                $liq = $liqSvc->getOrCreateLiquidation($sid, $today, $tz);
                if ($liq && $liq->status === 'approved') {
                    $this->warn("  seller $sid: el día $today está APROBADO — se omite (reabrí o elegí otro día).");
                    return;
                }

                foreach ($r['days'] as $d) {
                    if ($d['status'] !== 'reversible') continue;
                    foreach ($d['credits'] as $c) {
                        // Token estable para idempotencia (mismo formato que se
                        // busca y se escribe). Un ajuste por crédito.
                        $marker = self::TAG . ":cred:{$c['cid']}";
                        $exists = Income::where('user_id', $r['user_id'])
                            ->where('description', 'like', "%{$marker}%")->exists();
                        if ($exists) { $skipped++; continue; }

                        $desc = self::TAG . " credito #{$c['cid']} ({$c['cli']}) +\$" . number_format($c['pend'], 2)
                            . ". Restado indebidamente el {$d['date']} (liq #{$d['liq']}). Sigue en Cartera Irrecuperable."
                            . " Regla: el irrecuperable no afecta la caja. [{$marker}]";

                        $data = [
                            'value'              => $c['pend'],
                            'description'        => $desc,
                            'user_id'            => $r['user_id'],
                            'business_timezone'  => $tz,
                            'business_timestamp' => $now->format('Y-m-d H:i:s'),
                            'business_date'      => $today,
                        ];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('incomes', 'created_by')) {
                            $data['created_by'] = null; // generado por sistema
                        }
                        Income::create($data);
                        $created++;
                    }
                }

                // Recalcular el día abierto para que el/los ingresos entren a la caja.
                $liqSvc->recalculateLiquidation($sid, $today, $tz);
            });
            $sellersTouched++;
        }

        $this->newLine();
        $this->info("APLICADO: $created ingresos creados, $skipped ya existían (idempotente), $sellersTouched vendedores.");
        $this->line("Se pueden identificar/borrar buscando la etiqueta '" . self::TAG . "' en Ingresos (están en el día abierto).");
        return self::SUCCESS;
    }

    /**
     * Deshace la normalización: borra (soft-delete) los ingresos de ajuste
     * [AJUSTE-NORM-IRREC] y recalcula los días afectados para que la caja
     * vuelva a como estaba. Dry-run por defecto; --apply (--force) para borrar.
     */
    private function handleRevert()
    {
        $apply = (bool) $this->option('apply');
        $this->info('== Normalización del irrecuperable — REVERTIR ' . ($apply ? '(APLICAR)' : '(DRY-RUN)') . ' ==');
        $this->newLine();

        $liqSvc = app(LiquidationService::class);
        $incomes = Income::where('description', 'like', '%' . self::TAG . '%')->get();

        if ($incomes->isEmpty()) {
            $this->info('No hay ingresos de ajuste (' . self::TAG . ') para revertir.');
            return self::SUCCESS;
        }

        $total = 0.0;
        foreach ($incomes->groupBy('user_id') as $userId => $items) {
            $sum = (float) $items->sum('value');
            $total += $sum;
            $name = optional(optional(Seller::where('user_id', $userId)->first())->user)->name ?? "user $userId";
            $this->line("  " . str_pad($name, 18) . $items->count() . " ingresos   \$" . number_format($sum, 2));
        }
        $this->newLine();
        $this->line("<fg=yellow>TOTAL a revertir: {$incomes->count()} ingresos, \$" . number_format($total, 2) . "</>");
        $this->newLine();

        if (!$apply) {
            $this->comment('Dry-run: no se borró nada. Agregá --apply para revertir.');
            return self::SUCCESS;
        }
        if (!$this->option('force')
            && !$this->confirm("Vas a BORRAR {$incomes->count()} ingresos de ajuste (\$" . number_format($total, 2) . "). ¿Continuar?")) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $recalcs = [];
        DB::transaction(function () use ($incomes, &$deleted, &$recalcs) {
            foreach ($incomes as $inc) {
                $seller = Seller::where('user_id', $inc->user_id)->first();
                if ($seller) {
                    $tz = TimezoneHelper::getSellerTimezone($seller);
                    $bd = $inc->business_date instanceof \Carbon\Carbon
                        ? $inc->business_date->format('Y-m-d')
                        : substr((string) $inc->business_date, 0, 10);
                    $recalcs[$seller->id . '|' . $bd] = ['sid' => $seller->id, 'date' => $bd, 'tz' => $tz];
                }
                $inc->delete(); // soft-delete (reversible)
                $deleted++;
            }
        });

        foreach ($recalcs as $rc) {
            $liqSvc->recalculateLiquidation($rc['sid'], $rc['date'], $rc['tz']);
        }

        $this->newLine();
        $this->info("REVERTIDO: $deleted ingresos borrados (soft-delete), " . count($recalcs) . " días recalculados.");
        return self::SUCCESS;
    }
}
