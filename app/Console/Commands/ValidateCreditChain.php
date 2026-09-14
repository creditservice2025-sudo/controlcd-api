<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Validación READ-ONLY de la cadena completa de un crédito:
 *
 *     credits -> installments -> payments -> payment_installments
 *
 * NO MODIFICA NADA. Recorre los créditos, evalúa las invariantes que el
 * flujo de escritura de PaymentService debería garantizar y emite el detalle
 * de cada crédito afectado CON SU VENDEDOR, para poder verificar caso por
 * caso antes de aplicar cualquier corrección.
 *
 * IMPORTANTE — el abono de cuota es legítimo:
 * Cuando un cliente abona menos de lo que falta para completar la cuota, el
 * dinero queda en `payments.unapplied_amount` y la cuota no se marca. Eso es
 * el comportamiento diseñado (ver PaymentService::reapplyPayments, que hace
 * `break` si el stack no cubre la cuota entera), NO un error. Por eso el
 * saldo real del cliente es:
 *
 *     saldo_real = SUM(quota_amount - paid_amount) - SUM(unapplied_amount)
 *
 * Un abono sano NO aparece como hallazgo. Solo se reporta cuando alguna
 * invariante se rompe de verdad.
 *
 * Invariantes evaluadas:
 *   C1  credits.total_amount        = SUM(installments.quota_amount)
 *   C2  SUM(payments.amount)        = SUM(applied_amount) + SUM(unapplied_amount)
 *   C3  installments.paid_amount    = SUM(payment_installments.applied_amount)
 *   C4  installments.paid_amount   <= installments.quota_amount   (sobre-aplicación)
 *   C5  installments.status         coherente con paid_amount vs quota_amount
 *   C6  credits.remaining_amount    = saldo_real
 *   C7  el crédito tiene al menos una cuota viva
 *
 * Uso:
 *   php artisan credits:validate-chain
 *   php artisan credits:validate-chain --seller-id=72
 *   php artisan credits:validate-chain --only=C6 --show=50
 *   php artisan credits:validate-chain --credit-id=97535
 */
class ValidateCreditChain extends Command
{
    protected $signature = 'credits:validate-chain
                            {--seller-id= : Validar solo la ruta de un vendedor}
                            {--credit-id= : Validar un único crédito (modo detalle)}
                            {--status= : Filtrar por status del crédito (ej: Vigente)}
                            {--only= : Reportar solo un check (C1..C7), separados por coma}
                            {--threshold=0.01 : Diferencia mínima en $ para considerar descuadre}
                            {--show=25 : Cuántos créditos afectados listar en pantalla}
                            {--csv= : Ruta del CSV de salida (por defecto storage/app/)}';

    protected $description = 'Valida READ-ONLY la cadena credits→installments→payments→payment_installments y lista los créditos afectados con su vendedor';

    /** Etiquetas legibles de cada invariante. */
    private const CHECKS = [
        'C1' => 'total_amount != SUM(quota_amount)',
        'C2' => 'pagos != aplicado + sin_aplicar',
        'C3' => 'paid_amount != SUM(applied_amount)',
        'C4' => 'cuota sobre-aplicada (paid > quota)',
        'C5' => 'status de cuota incoherente',
        'C6' => 'remaining_amount != saldo real',
        'C7' => 'crédito sin cuotas vivas',
    ];

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        $only = $this->option('only')
            ? array_map('trim', explode(',', strtoupper($this->option('only'))))
            : null;

        if ($creditId = $this->option('credit-id')) {
            return $this->detalleDeUnCredito((int) $creditId, $threshold);
        }

        $this->info('Validando la cadena credits → installments → payments → payment_installments');
        $this->line('<comment>READ-ONLY: este comando no modifica ningún registro.</comment>');
        $this->newLine();

        $base = DB::table('credits as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('sellers as s', 's.id', '=', 'c.seller_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->whereNull('c.deleted_at');

        if ($sellerId = $this->option('seller-id')) {
            $base->where('c.seller_id', $sellerId);
        }
        if ($status = $this->option('status')) {
            $base->where('c.status', $status);
        }

        $total = (clone $base)->count();
        $this->info("Créditos a revisar: " . number_format($total));

        $csvPath = $this->option('csv')
            ?: storage_path('app/validate_chain_' . now()->format('Ymd_His') . '.csv');
        $fp = fopen($csvPath, 'w');
        // BOM para que Excel abra los acentos bien.
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'checks_fallidos', 'credito_id', 'status', 'vendedor_id', 'vendedor',
            'cliente_id', 'cliente', 'documento',
            'total_amount', 'suma_cuotas', 'deuda_cuotas', 'pagos_recibidos',
            'aplicado_a_cuotas', 'sin_aplicar_abono', 'saldo_real',
            'remaining_amount_db', 'diferencia_vs_db',
            'cuotas_vivas', 'cuotas_borradas', 'cuotas_con_paid_desc',
            'cuotas_sobre_aplicadas', 'cuotas_status_incoherente',
            'detalle',
        ]);

        $conteo = array_fill_keys(array_keys(self::CHECKS), 0);
        $montoPorCheck = array_fill_keys(array_keys(self::CHECKS), 0.0);
        $porVendedor = [];
        $muestra = [];
        $afectados = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $base->select(
            'c.id as credit_id', 'c.status', 'c.seller_id', 'c.client_id',
            'c.credit_value', 'c.total_amount', 'c.remaining_amount',
            'cl.name as cliente', 'cl.dni as documento', 'u.name as vendedor'
        )->orderBy('c.id')->chunk(500, function ($credits) use (
            $fp, $bar, $threshold, $only, &$conteo, &$montoPorCheck, &$porVendedor, &$muestra, &$afectados
        ) {
            $ids = collect($credits)->pluck('credit_id')->all();
            $inst = $this->statsDeCuotas($ids);
            $pago = $this->statsDePagos($ids);

            foreach ($credits as $c) {
                $bar->advance();

                $i = $inst[$c->credit_id] ?? null;
                $p = $pago[$c->credit_id] ?? null;

                $sumaCuotas   = round((float) ($i->suma_quota   ?? 0), 2);
                $deudaCuotas  = round((float) ($i->deuda        ?? 0), 2);
                $paidCuotas   = round((float) ($i->suma_paid    ?? 0), 2);
                $cuotasVivas  = (int)   ($i->vivas              ?? 0);
                $cuotasBorr   = (int)   ($i->borradas           ?? 0);
                $descPaid     = (int)   ($i->paid_desc          ?? 0);
                $sobreAplic   = (int)   ($i->sobre_aplicadas    ?? 0);
                $statusIncoh  = (int)   ($i->status_incoherente ?? 0);
                $aplicadoInst = round((float) ($i->aplicado     ?? 0), 2);

                $pagos        = round((float) ($p->pagos        ?? 0), 2);
                $sinAplicar   = round((float) ($p->sin_aplicar  ?? 0), 2);
                $aplicado     = round((float) ($p->aplicado     ?? 0), 2);

                $totalAmount  = round((float) $c->total_amount, 2);
                $remainingDb  = round((float) $c->remaining_amount, 2);

                // El abono legítimo se descuenta acá: el dinero ya está en caja
                // aunque todavía no haya completado ninguna cuota.
                $saldoReal    = round(max(0, $deudaCuotas - $sinAplicar), 2);

                $fallos = [];
                $detalle = [];

                if ($cuotasVivas === 0) {
                    $fallos[] = 'C7';
                    $detalle[] = 'sin cuotas vivas';
                    $montoPorCheck['C7'] += $remainingDb;
                } else {
                    if (abs($totalAmount - $sumaCuotas) > $threshold) {
                        $fallos[] = 'C1';
                        $detalle[] = sprintf('total_amount %s vs cuotas %s', $totalAmount, $sumaCuotas);
                        $montoPorCheck['C1'] += abs($totalAmount - $sumaCuotas);
                    }
                }

                if (abs($pagos - $aplicado - $sinAplicar) > $threshold) {
                    $fallos[] = 'C2';
                    $detalle[] = sprintf('pagos %s != aplicado %s + sin_aplicar %s', $pagos, $aplicado, $sinAplicar);
                    $montoPorCheck['C2'] += abs($pagos - $aplicado - $sinAplicar);
                }

                if ($descPaid > 0 || abs($paidCuotas - $aplicadoInst) > $threshold) {
                    $fallos[] = 'C3';
                    $detalle[] = sprintf('%d cuota(s) con paid_amount != applied', max($descPaid, 1));
                    $montoPorCheck['C3'] += abs($paidCuotas - $aplicadoInst);
                }

                if ($sobreAplic > 0) {
                    $fallos[] = 'C4';
                    $detalle[] = sprintf('%d cuota(s) con paid > quota', $sobreAplic);
                    $montoPorCheck['C4'] += (float) ($i->exceso ?? 0);
                }

                if ($statusIncoh > 0) {
                    $fallos[] = 'C5';
                    $detalle[] = sprintf('%d cuota(s) con status incoherente', $statusIncoh);
                }

                if (abs($remainingDb - $saldoReal) > $threshold) {
                    $fallos[] = 'C6';
                    $detalle[] = sprintf('remaining_amount %s vs saldo real %s', $remainingDb, $saldoReal);
                    $montoPorCheck['C6'] += abs($remainingDb - $saldoReal);
                }

                if ($only) {
                    $fallos = array_values(array_intersect($fallos, $only));
                }
                if (!$fallos) {
                    continue;
                }

                $afectados++;
                foreach ($fallos as $f) {
                    $conteo[$f]++;
                }

                $vendedor = $c->vendedor ?: '(sin vendedor)';
                if (!isset($porVendedor[$vendedor])) {
                    $porVendedor[$vendedor] = ['n' => 0, 'monto' => 0.0, 'seller_id' => $c->seller_id];
                }
                $porVendedor[$vendedor]['n']++;
                $porVendedor[$vendedor]['monto'] += abs($remainingDb - $saldoReal);

                $fila = [
                    implode('+', $fallos), $c->credit_id, $c->status, $c->seller_id, $vendedor,
                    $c->client_id, $c->cliente, $c->documento,
                    $totalAmount, $sumaCuotas, $deudaCuotas, $pagos,
                    $aplicado, $sinAplicar, $saldoReal,
                    $remainingDb, round($remainingDb - $saldoReal, 2),
                    $cuotasVivas, $cuotasBorr, $descPaid, $sobreAplic, $statusIncoh,
                    implode('; ', $detalle),
                ];
                fputcsv($fp, $fila);

                if (count($muestra) < (int) $this->option('show')) {
                    $muestra[] = [
                        implode('+', $fallos),
                        '#00' . $c->credit_id,
                        mb_substr($vendedor, 0, 18),
                        mb_substr((string) $c->cliente, 0, 22),
                        $c->status,
                        number_format($remainingDb, 2),
                        number_format($saldoReal, 2),
                    ];
                }
            }
        });

        $bar->finish();
        fclose($fp);
        $this->newLine(2);

        $this->line('<options=bold>RESULTADO POR INVARIANTE</>');
        $filas = [];
        foreach (self::CHECKS as $code => $desc) {
            if ($only && !in_array($code, $only, true)) {
                continue;
            }
            $filas[] = [
                $code,
                $desc,
                number_format($conteo[$code]),
                $montoPorCheck[$code] > 0 ? '$' . number_format($montoPorCheck[$code], 2) : '-',
                $conteo[$code] === 0 ? 'OK' : 'REVISAR',
            ];
        }
        $this->table(['', 'Invariante', 'Créditos', 'Monto', ''], $filas);

        if ($afectados === 0) {
            $this->info('Cadena consistente: ningún crédito rompe las invariantes.');
            $this->line("CSV (vacío): {$csvPath}");
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>CRÉDITOS AFECTADOS POR VENDEDOR</>');
        uasort($porVendedor, fn ($a, $b) => $b['n'] <=> $a['n']);
        $filasV = [];
        foreach (array_slice($porVendedor, 0, 20, true) as $nombre => $d) {
            $filasV[] = [$d['seller_id'], $nombre, number_format($d['n']), '$' . number_format($d['monto'], 2)];
        }
        $this->table(['seller_id', 'Vendedor', 'Créditos', 'Desfase vs BD'], $filasV);
        if (count($porVendedor) > 20) {
            $this->line('  ... y ' . (count($porVendedor) - 20) . ' vendedor(es) más en el CSV.');
        }

        $this->newLine();
        $this->line('<options=bold>MUESTRA DE CRÉDITOS AFECTADOS</>');
        $this->table(
            ['Checks', 'Crédito', 'Vendedor', 'Cliente', 'Status', 'remaining BD', 'saldo real'],
            $muestra
        );

        $this->newLine();
        $this->warn("Créditos afectados: " . number_format($afectados) . " de " . number_format($total));
        $this->info("Detalle completo por crédito y vendedor: {$csvPath}");
        $this->line('Para inspeccionar uno: <comment>php artisan credits:validate-chain --credit-id=ID</comment>');

        return self::SUCCESS;
    }

    /** Agregados de cuotas por crédito, en una sola consulta por chunk. */
    private function statsDeCuotas(array $ids)
    {
        // La derivada se acota al chunk. Sin este filtro MySQL materializa
        // payment_installments entero (1,18M filas) una vez por chunk.
        $in = $this->listaIds($ids);

        return DB::table('installments as i')
            ->leftJoin(DB::raw("(
                SELECT pi.installment_id, SUM(pi.applied_amount) as aplicado
                FROM payment_installments pi
                INNER JOIN payments p ON p.id = pi.payment_id AND p.deleted_at IS NULL
                WHERE pi.deleted_at IS NULL AND p.credit_id IN ({$in})
                GROUP BY pi.installment_id
            ) as a"), 'a.installment_id', '=', 'i.id')
            ->whereIn('i.credit_id', $ids)
            ->groupBy('i.credit_id')
            ->select('i.credit_id')
            ->selectRaw('SUM(CASE WHEN i.deleted_at IS NULL THEN 1 ELSE 0 END) as vivas')
            ->selectRaw('SUM(CASE WHEN i.deleted_at IS NOT NULL THEN 1 ELSE 0 END) as borradas')
            ->selectRaw('COALESCE(SUM(CASE WHEN i.deleted_at IS NULL THEN i.quota_amount END), 0) as suma_quota')
            ->selectRaw('COALESCE(SUM(CASE WHEN i.deleted_at IS NULL THEN i.paid_amount END), 0) as suma_paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN i.deleted_at IS NULL THEN i.quota_amount - i.paid_amount END), 0) as deuda')
            ->selectRaw('COALESCE(SUM(CASE WHEN i.deleted_at IS NULL THEN COALESCE(a.aplicado, 0) END), 0) as aplicado')
            ->selectRaw('SUM(CASE WHEN i.deleted_at IS NULL AND ABS(i.paid_amount - COALESCE(a.aplicado, 0)) > 0.01 THEN 1 ELSE 0 END) as paid_desc')
            ->selectRaw('SUM(CASE WHEN i.deleted_at IS NULL AND i.paid_amount - i.quota_amount > 0.01 THEN 1 ELSE 0 END) as sobre_aplicadas')
            ->selectRaw('COALESCE(SUM(CASE WHEN i.deleted_at IS NULL AND i.paid_amount - i.quota_amount > 0.01 THEN i.paid_amount - i.quota_amount END), 0) as exceso')
            // Coherencia de status. El abono parcial DEBE quedar en 'Parcial';
            // sin pago en 'Pendiente'/'Atrasado'; cubierta en 'Pagado'.
            ->selectRaw("SUM(CASE WHEN i.deleted_at IS NULL AND NOT (
                    (i.paid_amount <= 0.001 AND i.status IN ('Pendiente','Atrasado'))
                 OR (i.paid_amount + 0.001 >= i.quota_amount AND i.status = 'Pagado')
                 OR (i.paid_amount > 0.001 AND i.paid_amount + 0.001 < i.quota_amount AND i.status = 'Parcial')
                ) THEN 1 ELSE 0 END) as status_incoherente")
            ->get()
            ->keyBy('credit_id');
    }

    /**
     * Los ids vienen de la BD como enteros; se castean igual antes de
     * interpolarlos, para que la derivada no sea una vía de inyección.
     */
    private function listaIds(array $ids): string
    {
        return implode(',', array_map('intval', $ids)) ?: '0';
    }

    /** Agregados de pagos por crédito, en una sola consulta por chunk. */
    private function statsDePagos(array $ids)
    {
        $in = $this->listaIds($ids);

        return DB::table('payments as p')
            ->leftJoin(DB::raw("(
                SELECT pi.payment_id, SUM(pi.applied_amount) as aplicado
                FROM payment_installments pi
                INNER JOIN payments p2 ON p2.id = pi.payment_id
                WHERE pi.deleted_at IS NULL AND p2.credit_id IN ({$in})
                GROUP BY pi.payment_id
            ) as a"), 'a.payment_id', '=', 'p.id')
            ->whereIn('p.credit_id', $ids)
            ->whereNull('p.deleted_at')
            ->groupBy('p.credit_id')
            ->select('p.credit_id')
            ->selectRaw('COALESCE(SUM(p.amount), 0) as pagos')
            ->selectRaw('COALESCE(SUM(p.unapplied_amount), 0) as sin_aplicar')
            ->selectRaw('COALESCE(SUM(COALESCE(a.aplicado, 0)), 0) as aplicado')
            ->get()
            ->keyBy('credit_id');
    }

    /** Modo detalle: un crédito, cuota por cuota y pago por pago. */
    private function detalleDeUnCredito(int $creditId, float $threshold): int
    {
        $c = DB::table('credits as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('sellers as s', 's.id', '=', 'c.seller_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('c.id', $creditId)
            ->select('c.*', 'cl.name as cliente', 'u.name as vendedor')
            ->first();

        if (!$c) {
            $this->error("El crédito {$creditId} no existe.");
            return self::FAILURE;
        }

        $this->info("Crédito #00{$c->id}  ·  {$c->cliente}  ·  vendedor: " . ($c->vendedor ?: '(sin vendedor)'));
        $this->line("status={$c->status}  credit_value={$c->credit_value}  total_amount={$c->total_amount}  remaining_amount={$c->remaining_amount}");
        $this->newLine();

        $this->line('<options=bold>CUOTAS</>');
        $cuotas = DB::table('installments as i')
            ->leftJoin(DB::raw('(
                SELECT pi.installment_id, SUM(pi.applied_amount) as aplicado, COUNT(*) as n
                FROM payment_installments pi
                INNER JOIN payments p ON p.id = pi.payment_id AND p.deleted_at IS NULL
                WHERE pi.deleted_at IS NULL GROUP BY pi.installment_id
            ) as a'), 'a.installment_id', '=', 'i.id')
            ->where('i.credit_id', $creditId)
            ->orderBy('i.quota_number')
            ->select('i.*', 'a.aplicado', 'a.n')
            ->get();

        $filas = [];
        foreach ($cuotas as $i) {
            $aplicado = (float) ($i->aplicado ?? 0);
            $ok = abs((float) $i->paid_amount - $aplicado) <= $threshold;
            $filas[] = [
                $i->quota_number,
                $i->due_date,
                number_format($i->quota_amount, 2),
                number_format($i->paid_amount, 2),
                number_format($aplicado, 2),
                $i->status,
                $i->deleted_at ? 'BORRADA' : '',
                $ok ? 'ok' : '<-- DESCUADRE',
            ];
        }
        $this->table(['#', 'Vence', 'Cuota', 'paid_amount', 'aplicado', 'Status', '', ''], $filas);

        $this->line('<options=bold>PAGOS</>');
        $pagos = DB::table('payments as p')
            ->leftJoin(DB::raw('(
                SELECT pi.payment_id, SUM(pi.applied_amount) as aplicado
                FROM payment_installments pi WHERE pi.deleted_at IS NULL GROUP BY pi.payment_id
            ) as a'), 'a.payment_id', '=', 'p.id')
            ->where('p.credit_id', $creditId)
            ->orderBy('p.business_date')
            ->select('p.*', 'a.aplicado')
            ->get();

        $filas = [];
        foreach ($pagos as $p) {
            $aplicado = (float) ($p->aplicado ?? 0);
            $sin = (float) $p->unapplied_amount;
            $ok = abs((float) $p->amount - $aplicado - $sin) <= $threshold;
            $filas[] = [
                $p->id,
                $p->business_date,
                number_format($p->amount, 2),
                number_format($aplicado, 2),
                number_format($sin, 2),
                $p->status,
                $p->deleted_at ? 'BORRADO' : '',
                $ok ? 'ok' : '<-- DESCUADRE',
            ];
        }
        $this->table(['id', 'Día', 'Monto', 'aplicado', 'sin aplicar (abono)', 'Status', '', ''], $filas);

        $vivas = $cuotas->whereNull('deleted_at');
        $deuda = round($vivas->sum(fn ($i) => (float) $i->quota_amount - (float) $i->paid_amount), 2);
        $sinAplicar = round($pagos->whereNull('deleted_at')->sum('unapplied_amount'), 2);
        $saldoReal = round(max(0, $deuda - $sinAplicar), 2);

        $this->newLine();
        $this->line('  deuda por cuotas vivas : $' . number_format($deuda, 2));
        $this->line('  abono sin aplicar      : $' . number_format($sinAplicar, 2));
        $this->line('  <options=bold>saldo real             : $' . number_format($saldoReal, 2) . '</>');
        $this->line('  remaining_amount en BD : $' . number_format((float) $c->remaining_amount, 2));

        if (abs((float) $c->remaining_amount - $saldoReal) > $threshold) {
            $this->error('  DESCUADRE: la BD difiere del saldo real en $'
                . number_format(abs((float) $c->remaining_amount - $saldoReal), 2));
        } else {
            $this->info('  La columna coincide con el saldo real.');
        }

        return self::SUCCESS;
    }
}
