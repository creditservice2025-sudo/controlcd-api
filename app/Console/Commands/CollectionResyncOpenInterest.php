<?php

namespace App\Console\Commands;

use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionCreditAudit;
use App\Models\Collection\CollectionInstallment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resincroniza el interés de la cuota vigente de los créditos abiertos.
 *
 * Por qué existe: hasta ahora, agregar capital a un crédito NO recalculaba la
 * cuota de interés abierta, así que quedaba cobrando el interés del capital
 * anterior. El código ya lo recalcula al agregar/corregir capital, pero las
 * cuotas escritas antes de ese cambio siguen con el valor viejo. Este comando
 * las pone al día una sola vez.
 *
 * Fórmula: interés = CAPITAL VIVO x tasa, donde capital vivo = amount menos el
 * capital ya devuelto. NO es `amount` a secas: en un crédito abierto el cliente
 * amortiza y el interés del mes siguiente baja.
 *
 * Alcance deliberado:
 *  - solo créditos `monthly_interest_open` y activos (los esquemas viejos
 *    capital_interest / interest_only usan otra fórmula y no se tocan);
 *  - solo la cuota abierta (pendiente o parcial): una cuota ya pagada se cobró
 *    con el capital que había entonces y reescribirla falsearía el histórico;
 *  - por defecto NO baja cuotas que ya tengan abonos, porque subir/bajar lo
 *    esperado sobre algo parcialmente cobrado necesita decisión humana
 *    (se listan aparte; usar --incluir-abonadas para incluirlas).
 *
 * Cada cambio queda auditado en collection_credit_audits.
 *
 * Uso:
 *   php artisan collection:resync-open-interest --dry-run
 *   php artisan collection:resync-open-interest
 *   php artisan collection:resync-open-interest --credit=21
 */
class CollectionResyncOpenInterest extends Command
{
    protected $signature = 'collection:resync-open-interest
        {--dry-run : Solo mostrar lo que cambiaría, sin escribir}
        {--credit=* : Limitar a estos IDs de crédito}
        {--incluir-abonadas : Incluir cuotas que ya tienen abonos parciales}';

    protected $description = 'Pone al día el interés de la cuota vigente de los créditos abiertos (capital vivo x tasa)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_filter((array) $this->option('credit'));
        $incluirAbonadas = (bool) $this->option('incluir-abonadas');

        $credits = CollectionCredit::query()
            ->where('status', 'active')
            ->when($only, fn ($q) => $q->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        $rows = [];
        $omitidasPorAbono = [];

        foreach ($credits as $credit) {
            $meta = is_array($credit->metadata) ? $credit->metadata : [];
            if (($meta['installment_distribution_mode'] ?? null) !== 'monthly_interest_open') {
                continue;
            }

            $installments = CollectionInstallment::query()
                ->where('company_id', $credit->company_id)
                ->where('credit_id', $credit->id)
                ->whereNull('deleted_at')
                ->orderBy('installment_number')
                ->get();

            $open = $installments->first(
                fn ($i) => in_array(strtolower((string) $i->status), ['pendiente', 'parcial'], true)
            );
            if (!$open) {
                continue;
            }

            $livePrincipal = max(0, round(
                (float) $credit->amount - (float) $installments->sum('principal_paid'),
                2
            ));
            $expected = round($livePrincipal * (float) $credit->interest_rate / 100, 2);
            $current = (float) $open->interest_amount;

            if (abs($expected - $current) < 0.01) {
                continue;
            }

            if ((float) $open->paid_amount > 0 && !$incluirAbonadas) {
                $omitidasPorAbono[] = [$credit->id, $current, $expected, (float) $open->paid_amount];
                continue;
            }

            $rows[] = compact('credit', 'open', 'livePrincipal', 'expected', 'current');
        }

        if (empty($rows) && empty($omitidasPorAbono)) {
            $this->info('Todas las cuotas abiertas están al día. Nada que hacer.');
            return self::SUCCESS;
        }

        if (!empty($rows)) {
            $this->table(
                ['Crédito', 'Capital vivo', 'Tasa', 'Cuota actual', 'Cuota correcta', 'Diferencia'],
                array_map(fn ($r) => [
                    $r['credit']->id,
                    number_format($r['livePrincipal'], 2),
                    $r['credit']->interest_rate . '%',
                    number_format($r['current'], 2),
                    number_format($r['expected'], 2),
                    number_format($r['expected'] - $r['current'], 2),
                ], $rows)
            );
        }

        if (!empty($omitidasPorAbono)) {
            $this->warn('Omitidas por tener abonos (usar --incluir-abonadas si querés tocarlas):');
            $this->table(
                ['Crédito', 'Cuota actual', 'Cuota correcta', 'Abonado'],
                array_map(fn ($r) => [
                    $r[0], number_format($r[1], 2), number_format($r[2], 2), number_format($r[3], 2),
                ], $omitidasPorAbono)
            );
        }

        if ($dry) {
            $this->info('DRY-RUN: no se escribió nada. Quitá --dry-run para aplicar.');
            return self::SUCCESS;
        }

        if (empty($rows)) {
            return self::SUCCESS;
        }

        $applied = 0;
        DB::connection('collection_pgsql')->transaction(function () use ($rows, &$applied) {
            foreach ($rows as $r) {
                $credit = $r['credit'];
                $open = $r['open'];
                $interestPaid = (float) ($open->interest_paid ?? 0);

                $open->amount = $r['expected'];
                $open->interest_amount = $r['expected'];
                $open->principal_amount = 0;
                $open->status = $interestPaid >= $r['expected']
                    ? 'pagado'
                    : ($interestPaid > 0 ? 'parcial' : 'pendiente');
                $open->save();

                CollectionCreditAudit::query()->create([
                    'company_id' => $credit->company_id,
                    'credit_id' => $credit->id,
                    'action' => 'interest_resync',
                    'user_id' => null, // corrida por consola, no por un usuario
                    'ip_address' => 'console',
                    'changes' => [
                        'installment_number' => $open->installment_number,
                        'live_principal' => $r['livePrincipal'],
                        'interest_rate' => (float) $credit->interest_rate,
                        'old' => ['amount' => $r['current']],
                        'new' => ['amount' => $r['expected']],
                    ],
                ]);

                $applied++;
            }
        });

        $this->info("Listo: {$applied} cuota(s) actualizada(s) y auditada(s).");

        return self::SUCCESS;
    }
}
