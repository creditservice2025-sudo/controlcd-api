<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionCreditAudit;
use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionLedger;
use App\Services\Collection\CollectionWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige créditos de Deuda & Abono cuyo monto se guardó cortado al crearlos.
 *
 * Por qué existe: el campo "Valor del crédito" tomaba el punto como decimal
 * ("60.000.000" → 60) y el usuario terminaba cargando solo las primeras cifras.
 * Después el monto se corrigió (por la edición o a mano en la base), pero la
 * cuota 1 y la caja quedaron con el valor cortado:
 *   - principal_base de la cuota ("3% de $ 60.000") no coincide con el crédito;
 *   - el interés de la cuota se calculó sobre el monto cortado ($ 1.800 en vez
 *     de $ 1.800.000) cuando la corrección no pasó por la edición;
 *   - la caja registró el desembolso cortado y nunca el ajuste.
 *
 * Qué hace, por crédito:
 *   1. cuota 1: principal_base = monto del crédito;
 *   2. cuota 1: interés = monto x tasa; el estado se recalcula con lo ya
 *      cobrado de interés (mismo criterio que collection:resync-open-interest).
 *      Si el interés ya cobrado no alcanza, la cuota queda "parcial";
 *   3. caja: movimiento loan_issue_adjustment por la diferencia entre el monto
 *      y lo desembolsado (append-only, igual que la edición del crédito);
 *   4. un registro 'data_fix_amount' en collection_credit_audits con antes/después.
 *
 * Alcance deliberado (lo demás se lista para revisión manual):
 *   - solo créditos monthly_interest_open, sin adiciones de capital;
 *   - solo la cuota 1 (la única que nace del monto original);
 *   - el ajuste de caja solo si la wallet que resuelve el servicio es la misma
 *     del desembolso original.
 *
 * Uso:
 *   php artisan collection:fix-truncated-credit-amounts                       (detecta y simula)
 *   php artisan collection:fix-truncated-credit-amounts --credit=13 --credit=14
 *   php artisan collection:fix-truncated-credit-amounts --credit=13 --credit=14 --aplicar
 */
class CollectionFixTruncatedCreditAmounts extends Command
{
    protected $signature = 'collection:fix-truncated-credit-amounts
        {--credit=* : IDs de crédito a corregir (obligatorio con --aplicar)}
        {--company= : Limitar a una empresa}
        {--aplicar : Escribir los cambios (sin esto solo simula)}';

    protected $description = 'Corrige cuota 1 y caja de créditos Deuda & Abono con el monto cortado al crearlos';

    private const CONNECTION = 'collection_pgsql';
    private const TRUNCATION_RATIO = 10;

    public function handle(CollectionWalletService $wallets): int
    {
        $apply = (bool) $this->option('aplicar');
        $only = array_values(array_filter(array_map('intval', (array) $this->option('credit'))));

        if ($apply && empty($only)) {
            $this->error('Con --aplicar hay que indicar los créditos: --credit=13 --credit=14');
            return self::FAILURE;
        }

        $credits = CollectionCredit::query()
            ->where('status', 'active')
            ->when($only, fn ($q) => $q->whereIn('id', $only))
            ->when($this->option('company'), fn ($q, $c) => $q->where('company_id', (int) $c))
            ->orderBy('company_id')->orderBy('id')
            ->get();

        $plans = [];
        $manual = [];

        foreach ($credits as $credit) {
            $plan = $this->plan($credit);
            if ($plan === null) {
                continue; // consistente: nada que corregir
            }
            if (isset($plan['manual'])) {
                $manual[] = [$credit->company_id, $credit->id, $plan['manual']];
                continue;
            }
            $plans[] = $plan;
        }

        if (empty($plans) && empty($manual)) {
            $this->info('No hay créditos con el monto cortado. Nada que hacer.');
            return self::SUCCESS;
        }

        if ($plans) {
            $this->table(
                ['Emp.', 'Crédito', 'Moneda', 'Monto', 'Base cuota 1', 'Interés cuota 1', 'Estado cuota', 'Desembolsado → ajuste caja'],
                array_map(fn ($p) => [
                    $p['credit']->company_id,
                    $p['credit']->id,
                    $p['credit']->currency,
                    $this->n($p['amount']),
                    $this->change($p['old_base'], $p['new_base']),
                    $this->change($p['old_interest'], $p['new_interest']),
                    $p['old_status'] === $p['new_status'] ? $p['old_status'] : "{$p['old_status']} → {$p['new_status']}",
                    abs($p['cash_delta']) >= 0.01
                        ? $this->n($p['disbursed']) . ' → ' . ($p['cash_delta'] > 0 ? 'débito ' : 'reintegro ') . $this->n(abs($p['cash_delta']))
                        : $this->n($p['disbursed']) . ' (sin ajuste)',
                ], $plans)
            );

            foreach ($plans as $p) {
                if ($p['new_status'] !== 'pagado' && $p['old_status'] === 'pagado') {
                    $pending = $p['new_interest'] - $p['interest_paid'];
                    $this->warn("Crédito #{$p['credit']->id}: la cuota 1 vuelve a quedar con {$this->n($pending)} de interés por cobrar.");
                }
            }
        }

        if ($manual) {
            $this->warn('Requieren revisión manual (no se tocan):');
            $this->table(['Emp.', 'Crédito', 'Motivo'], $manual);
        }

        if (!$apply) {
            $this->info('SIMULACIÓN: no se escribió nada. Revisá la tabla y repetí con --credit=<id> ... --aplicar.');
            return self::SUCCESS;
        }

        $done = 0;
        foreach ($plans as $p) {
            DB::connection(self::CONNECTION)->transaction(function () use ($p, $wallets) {
                $this->applyPlan($p, $wallets);
            });
            $done++;
            $this->info("Crédito #{$p['credit']->id} corregido.");
        }

        $this->info("Listo: {$done} crédito(s) corregido(s) y auditado(s).");
        return self::SUCCESS;
    }

    /**
     * Qué habría que cambiar en el crédito. null = está consistente.
     */
    private function plan(CollectionCredit $credit): ?array
    {
        $meta = is_array($credit->metadata) ? $credit->metadata : [];
        $amount = round((float) $credit->amount, 2);
        $rate = (float) $credit->interest_rate;

        $first = CollectionInstallment::query()
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->where('installment_number', 1)
            ->whereNull('deleted_at')
            ->first();

        // Firma del corte: el monto es 10 veces o más lo que quedó en la cuota o
        // en la caja (60.000.000 vs 60.000). Una diferencia menor es normal
        // (adiciones de capital, abonos) y no es este problema.
        // Las adiciones de capital suben el monto sin tocar el desembolso ni la
        // cuota 1: se descuentan antes de comparar.
        $additions = (float) CollectionCapitalAddition::query()
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->sum('amount');
        $original = $amount - $additions;
        $disbursed = $this->disbursed($credit);
        $base = $first ? (float) $first->principal_base : 0.0;
        $baseWrong = $base > 0 && $original / $base >= self::TRUNCATION_RATIO;
        $cashWrong = $disbursed !== null && $disbursed > 0 && $original / $disbursed >= self::TRUNCATION_RATIO;

        if (!$baseWrong && !$cashWrong) {
            return null;
        }

        if (($meta['installment_distribution_mode'] ?? null) !== 'monthly_interest_open') {
            return ['manual' => 'Esquema de cuotas antiguo'];
        }
        if (!$first) {
            return ['manual' => 'No tiene cuota 1 vigente'];
        }
        $hasAdditions = CollectionCapitalAddition::query()
            ->where('company_id', $credit->company_id)
            ->where('credit_id', $credit->id)
            ->exists();
        if ($hasAdditions) {
            return ['manual' => 'Tiene adiciones de capital: la base de la cuota no es solo el monto'];
        }
        if ($disbursed === null) {
            return ['manual' => 'No se encontró el desembolso (loan_issue) en la caja'];
        }

        $newInterest = round($amount * $rate / 100, 2);
        $interestPaid = round((float) ($first->interest_paid ?? 0), 2);
        $newStatus = $interestPaid >= $newInterest
            ? 'pagado'
            : ($interestPaid > 0 || (float) $first->principal_paid > 0 ? 'parcial' : 'pendiente');

        return [
            'credit' => $credit,
            'installment' => $first,
            'amount' => $amount,
            'rate' => $rate,
            'old_base' => (float) $first->principal_base,
            'new_base' => $amount,
            'old_interest' => (float) $first->interest_amount,
            'new_interest' => $newInterest,
            'interest_paid' => $interestPaid,
            'old_status' => (string) $first->status,
            'new_status' => $newStatus,
            'disbursed' => $disbursed,
            'cash_delta' => round($amount - $disbursed, 2),
        ];
    }

    private function applyPlan(array $p, CollectionWalletService $wallets): void
    {
        /** @var CollectionCredit $credit */
        $credit = $p['credit'];
        /** @var CollectionInstallment $inst */
        $inst = CollectionInstallment::query()
            ->where('company_id', $credit->company_id)
            ->where('id', $p['installment']->id)
            ->lockForUpdate()
            ->firstOrFail();

        $inst->principal_base = $p['new_base'];
        $inst->interest_amount = $p['new_interest'];
        // Modo abierto: la cuota es de solo interés (el capital se abona aparte).
        $inst->amount = $p['new_interest'];
        $inst->status = $p['new_status'];
        $inst->save();

        $ledgerId = null;
        if (abs($p['cash_delta']) >= 0.01) {
            $currency = strtoupper($credit->currency ?: 'COP');
            $country = strtoupper($credit->country_code ?: 'CO');
            $originalWalletId = (int) CollectionLedger::query()
                ->where('company_id', $credit->company_id)
                ->where('reference_type', 'credit')
                ->where('reference_id', $credit->id)
                ->where('action_type', 'loan_issue')
                ->value('wallet_id');
            $resolved = $wallets->getOrCreateWallet((int) $credit->company_id, $currency, $country);

            if ((int) $resolved->id !== $originalWalletId) {
                throw new \RuntimeException(
                    "Crédito #{$credit->id}: la caja del desembolso ({$originalWalletId}) no es la que resuelve el servicio ({$resolved->id}). No se aplica nada."
                );
            }

            $ledger = $wallets->recordMovement([
                'company_id' => $credit->company_id,
                'currency' => $currency,
                'country_code' => $country,
                'amount' => abs($p['cash_delta']),
                'type' => $p['cash_delta'] > 0 ? 'debit' : 'credit',
                'action_type' => 'loan_issue_adjustment',
                'reference_type' => 'credit',
                'reference_id' => $credit->id,
                'description' => "Corrección de monto cortado al crear el crédito #{$credit->id}: "
                    . number_format($p['disbursed'], 2) . ' → ' . number_format($p['amount'], 2),
            ]);
            $ledgerId = $ledger->id ?? null;
        }

        CollectionCreditAudit::query()->create([
            'company_id' => $credit->company_id,
            'credit_id' => $credit->id,
            'action' => 'data_fix_amount',
            'user_id' => null, // corrida por consola
            'ip_address' => 'console',
            'changes' => [
                'reason' => 'Monto cortado al crear el crédito (el punto de miles se leía como decimal)',
                'installment_number' => 1,
                'old' => [
                    'principal_base' => $p['old_base'],
                    'interest_amount' => $p['old_interest'],
                    'status' => $p['old_status'],
                    'disbursed' => $p['disbursed'],
                ],
                'new' => [
                    'principal_base' => $p['new_base'],
                    'interest_amount' => $p['new_interest'],
                    'status' => $p['new_status'],
                    'disbursed' => $p['amount'],
                ],
                'ledger_id' => $ledgerId,
            ],
        ]);
    }

    /** Neto desembolsado según la caja (colocación ± ajustes). null si no hay colocación. */
    private function disbursed(CollectionCredit $credit): ?float
    {
        $rows = CollectionLedger::query()
            ->where('company_id', $credit->company_id)
            ->where('reference_type', 'credit')
            ->where('reference_id', $credit->id)
            ->whereIn('action_type', ['loan_issue', 'loan_issue_adjustment'])
            ->get(['type', 'amount', 'action_type']);

        if (!$rows->contains('action_type', 'loan_issue')) {
            return null;
        }

        return round($rows->sum(
            fn ($r) => $r->type === 'debit' ? (float) $r->amount : -(float) $r->amount
        ), 2);
    }

    private function n(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private function change(float $old, float $new): string
    {
        return abs($old - $new) < 0.01 ? $this->n($old) : $this->n($old) . ' → ' . $this->n($new);
    }
}
