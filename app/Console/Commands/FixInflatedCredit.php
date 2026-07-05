<?php

namespace App\Console\Commands;

use App\Models\Credit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige un crédito cuya cadena (total_amount + cuotas + pagos) quedó inflada
 * por un factor uniforme respecto a `credit_value` (ej: #5435 con total_amount
 * 36.000.000 cuando credit_value 300.000 → correcto 360.000, factor 100).
 *
 * SEGURO PARA PRODUCCIÓN:
 *  - Dry-run por defecto: sin `--apply` solo MUESTRA el antes y el plan, no escribe.
 *  - Transaccional: aplica todo o nada.
 *  - Idempotente: si el crédito ya es consistente, no hace nada.
 *  - Guardas: aborta si el factor no es limpio o si el plan de cuotas no suma
 *    total_amount (corrupción distinta que requiere revisión manual).
 *
 * Uso:
 *   php artisan credits:fix-inflated 5435            # dry-run (no escribe)
 *   php artisan credits:fix-inflated 5435 --apply    # aplica dentro de transacción
 *   php artisan credits:fix-inflated 5435 --factor=100 --apply   # forzar factor
 */
class FixInflatedCredit extends Command
{
    protected $signature = 'credits:fix-inflated
                            {credit : ID del crédito a corregir}
                            {--apply : Aplicar los cambios (por defecto es dry-run, no escribe)}
                            {--factor= : Forzar el factor de escala (por defecto se detecta)}';

    protected $description = 'Corrige un crédito con total_amount/cuotas/pagos inflados por un factor uniforme respecto a credit_value';

    /** Tolerancia en pesos para comparaciones de montos. */
    private const EPS = 0.01;

    public function handle(): int
    {
        $creditId = (int) $this->argument('credit');
        $apply = (bool) $this->option('apply');

        $credit = Credit::find($creditId);
        if (!$credit) {
            $this->error("Crédito #{$creditId} no existe.");
            return self::FAILURE;
        }

        $expected = round((float) $credit->credit_value * (1 + (float) $credit->total_interest / 100), 2);
        if ($expected <= 0) {
            $this->error("credit_value/total_interest inválidos (esperado <= 0). Revisión manual.");
            return self::FAILURE;
        }

        // Idempotencia: si ya está consistente, no hay nada que hacer.
        if (abs((float) $credit->total_amount - $expected) <= self::EPS) {
            $this->info("Crédito #{$creditId} ya es consistente (total_amount = {$expected}). Nada que hacer.");
            $this->renderDetail($creditId, 'ESTADO ACTUAL');
            return self::SUCCESS;
        }

        // Detección/validación del factor de inflado.
        $factor = $this->option('factor') !== null
            ? (float) $this->option('factor')
            : round((float) $credit->total_amount / $expected);

        if ($factor < 2) {
            $this->error("Factor detectado ({$factor}) < 2. No parece un inflado uniforme; revisión manual.");
            return self::FAILURE;
        }
        if (abs((float) $credit->total_amount - $expected * $factor) > self::EPS * $factor) {
            $this->error("total_amount ({$credit->total_amount}) no es múltiplo limpio de lo esperado ({$expected}) por factor {$factor}. Revisión manual.");
            return self::FAILURE;
        }

        // El plan de cuotas debe sumar total_amount (misma corrupción, escalada).
        $sumQuota = (float) DB::table('installments')
            ->where('credit_id', $creditId)->whereNull('deleted_at')->sum('quota_amount');
        if (abs($sumQuota - (float) $credit->total_amount) > self::EPS * $factor) {
            $this->error("Las cuotas (Σquota={$sumQuota}) no suman total_amount ({$credit->total_amount}). Corrupción distinta; revisión manual.");
            return self::FAILURE;
        }

        $this->warn("Crédito #{$creditId}: total_amount {$credit->total_amount} → {$expected} (factor {$factor}).");
        $this->renderDetail($creditId, 'ANTES');

        if (!$apply) {
            $this->info('DRY-RUN: no se escribió nada. Ejecuta con --apply para aplicar los cambios.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($credit, $creditId, $expected, $factor) {
            // 1) Crédito: total_amount al valor correcto.
            DB::table('credits')->where('id', $creditId)->update([
                'total_amount' => $expected,
                'updated_at'   => $credit->updated_at,
            ]);

            // 2) Cuotas: quota_amount y paid_amount / factor.
            foreach (DB::table('installments')->where('credit_id', $creditId)->whereNull('deleted_at')->get() as $i) {
                DB::table('installments')->where('id', $i->id)->update([
                    'quota_amount' => round((float) $i->quota_amount / $factor, 2),
                    'paid_amount'  => round((float) $i->paid_amount / $factor, 2),
                ]);
            }

            // 3) Pagos vivos: amount y unapplied_amount / factor.
            foreach (DB::table('payments')->where('credit_id', $creditId)->whereNull('deleted_at')->get() as $p) {
                DB::table('payments')->where('id', $p->id)->update([
                    'amount'           => round((float) $p->amount / $factor, 2),
                    'unapplied_amount' => round((float) $p->unapplied_amount / $factor, 2),
                ]);

                // 4) Distribución pago→cuota: applied_amount / factor.
                foreach (DB::table('payment_installments')->where('payment_id', $p->id)->whereNull('deleted_at')->get() as $pi) {
                    DB::table('payment_installments')->where('id', $pi->id)->update([
                        'applied_amount' => round((float) $pi->applied_amount / $factor, 2),
                    ]);
                }
            }

            // 5) Recalcular remaining_amount + status desde la verdad (cuotas).
            $credit->refresh()->recalculateRemainingAndStatus();
        });

        $this->info("Crédito #{$creditId} corregido correctamente.");
        $this->renderDetail($creditId, 'DESPUÉS');

        return self::SUCCESS;
    }

    /** Imprime el detalle del crédito, sus cuotas y pagos. */
    private function renderDetail(int $creditId, string $label): void
    {
        $c = DB::table('credits')->where('id', $creditId)->first();
        $this->line('');
        $this->line("========== {$label} — Crédito #{$creditId} ==========");
        $this->table(
            ['credit_value', 'interés %', 'total_amount', 'remaining_amount', 'status'],
            [[
                number_format((float) $c->credit_value, 2),
                $c->total_interest,
                number_format((float) $c->total_amount, 2),
                number_format((float) $c->remaining_amount, 2),
                $c->status,
            ]]
        );

        $ins = DB::table('installments')->where('credit_id', $creditId)->whereNull('deleted_at')->orderBy('id')->get();
        $this->table(
            ['cuota_id', 'quota_amount', 'paid_amount', 'status'],
            $ins->map(fn ($i) => [
                $i->id,
                number_format((float) $i->quota_amount, 2),
                number_format((float) $i->paid_amount, 2),
                $i->status,
            ])->all()
        );

        $pays = DB::table('payments')->where('credit_id', $creditId)->whereNull('deleted_at')->orderBy('id')->get();
        $this->table(
            ['pago_id', 'amount', 'unapplied', 'status', 'business_date'],
            $pays->map(fn ($p) => [
                $p->id,
                number_format((float) $p->amount, 2),
                number_format((float) $p->unapplied_amount, 2),
                $p->status,
                $p->business_date,
            ])->all()
        );
    }
}
