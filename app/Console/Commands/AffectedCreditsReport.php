<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Listado READ-ONLY de los créditos con remaining_amount desincronizado,
 * organizado VENDEDOR → CLIENTE → CRÉDITO, con la verificación cruzada que
 * permite confiar en la migración antes de aplicarla.
 *
 * Por cada crédito se calculan DOS caminos independientes hacia el saldo:
 *
 *   A) por cuotas   = SUM(quota_amount - paid_amount) - SUM(unapplied_amount)
 *   B) por pagos    = SUM(quota_amount) - SUM(payments.amount)
 *
 * A sale de installments; B sale de payments. Si ambos coinciden, el saldo
 * está confirmado por dos fuentes que no se hablan entre sí, y migrar es
 * seguro. Si NO coinciden, ese crédito necesita revisión manual: la columna
 * `verificacion` lo marca y `observacion` dice por qué.
 *
 * El abono es legítimo: plata recibida que todavía no completó una cuota
 * queda en unapplied_amount. Baja el saldo del cliente igual, por eso se
 * resta en A. (Ver Credit::outstandingAmount y PaymentService::create.)
 *
 * Uso:
 *   php artisan credits:affected-report
 *   php artisan credits:affected-report --seller-id=72          # con detalle
 *   php artisan credits:affected-report --pais=Colombia
 *   php artisan credits:affected-report --solo-descuadrados     # los dudosos
 */
class AffectedCreditsReport extends Command
{
    protected $signature = 'credits:affected-report
                            {--seller-id= : Una sola ruta, e imprime el detalle cliente por cliente}
                            {--pais= : Filtrar por país (ej: Colombia)}
                            {--status= : Filtrar por status del crédito}
                            {--solo-descuadrados : Solo los créditos donde las dos vías NO coinciden}
                            {--threshold=0.01 : Tolerancia en $}
                            {--csv= : Ruta del CSV de salida}';

    protected $description = 'Lista vendedor→cliente→crédito de los créditos afectados, con remaining actual y verificación cruzada contra payments';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        $this->info('Listando créditos afectados (READ-ONLY, no modifica nada)...');
        $filas = $this->consultar($threshold);

        if (empty($filas)) {
            $this->info('No hay créditos afectados con los filtros dados.');
            return self::SUCCESS;
        }

        $csvPath = $this->option('csv')
            ?: storage_path('app/afectados_' . now()->format('Ymd_His') . '.csv');
        $fp = fopen($csvPath, 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'pais', 'moneda', 'vendedor_id', 'vendedor',
            'cliente_id', 'cliente', 'documento',
            'credito_id', 'status', 'frecuencia',
            'total_del_credito', 'remaining_actual',
            'pagos_recibidos', 'aplicado_a_cuotas', 'sin_aplicar_abono',
            'deuda_por_cuotas', 'saldo_real_A_cuotas', 'saldo_real_B_pagos',
            'diferencia_vs_actual', 'verificacion', 'observacion',
        ]);

        $porVendedor = [];
        $porPais = [];
        $confirmados = 0;
        $dudosos = 0;

        foreach ($filas as $f) {
            $totalCuotas   = round((float) $f->total_cuotas, 2);
            $remActual     = round((float) $f->remaining_actual, 2);
            $pagos         = round((float) $f->pagos, 2);
            $aplicado      = round((float) $f->aplicado, 2);
            $sinAplicar    = round((float) $f->sin_aplicar, 2);
            $deudaCuotas   = round((float) $f->deuda_cuotas, 2);

            // Vía A: desde las cuotas, descontando el abono aún no aplicado.
            $saldoA = round(max(0, $deudaCuotas - $sinAplicar), 2);
            // Vía B: desde los pagos recibidos contra el total emitido.
            $saldoB = round(max(0, $totalCuotas - $pagos), 2);

            $coincide = abs($saldoA - $saldoB) <= $threshold;
            $obs = [];
            if (!$coincide) {
                $obs[] = sprintf('las dos vías difieren en $%s', number_format(abs($saldoA - $saldoB), 2));
                if (abs($aplicado + $sinAplicar - $pagos) > $threshold) {
                    $obs[] = 'pagos != aplicado + sin_aplicar';
                }
                if (abs($totalCuotas - (float) $f->total_amount) > $threshold) {
                    $obs[] = 'total_amount no coincide con la suma de cuotas';
                }
                if ((int) $f->cuotas_borradas > 0) {
                    $obs[] = $f->cuotas_borradas . ' cuota(s) borrada(s)';
                }
            }
            if ($sinAplicar > 0) {
                $obs[] = sprintf('abono de $%s sin aplicar', number_format($sinAplicar, 2));
            }
            if ((int) $f->npagos === 0) {
                $obs[] = 'nunca recibió un pago';
            }

            if ($this->option('solo-descuadrados') && $coincide) {
                continue;
            }

            $coincide ? $confirmados++ : $dudosos++;

            fputcsv($fp, [
                $f->pais, $f->moneda, $f->seller_id, $f->vendedor,
                $f->client_id, $f->cliente, $f->documento,
                $f->id, $f->status, $f->payment_frequency,
                $totalCuotas, $remActual,
                $pagos, $aplicado, $sinAplicar,
                $deudaCuotas, $saldoA, $saldoB,
                round($saldoA - $remActual, 2),
                $coincide ? 'CONFIRMADO' : 'REVISAR',
                implode('; ', $obs),
            ]);

            $vKey = $f->seller_id . '|' . ($f->vendedor ?: '(sin vendedor)');
            if (!isset($porVendedor[$vKey])) {
                $porVendedor[$vKey] = [
                    'pais' => $f->pais, 'moneda' => $f->moneda,
                    'clientes' => [], 'creditos' => 0,
                    'rem_actual' => 0.0, 'saldo_real' => 0.0,
                    'confirmados' => 0, 'dudosos' => 0,
                ];
            }
            $porVendedor[$vKey]['clientes'][$f->client_id] = true;
            $porVendedor[$vKey]['creditos']++;
            $porVendedor[$vKey]['rem_actual'] += $remActual;
            $porVendedor[$vKey]['saldo_real'] += $saldoA;
            $coincide ? $porVendedor[$vKey]['confirmados']++ : $porVendedor[$vKey]['dudosos']++;

            $pKey = $f->pais . ' (' . $f->moneda . ')';
            if (!isset($porPais[$pKey])) {
                $porPais[$pKey] = ['creditos' => 0, 'rem_actual' => 0.0, 'saldo_real' => 0.0, 'dudosos' => 0];
            }
            $porPais[$pKey]['creditos']++;
            $porPais[$pKey]['rem_actual'] += $remActual;
            $porPais[$pKey]['saldo_real'] += $saldoA;
            if (!$coincide) {
                $porPais[$pKey]['dudosos']++;
            }
        }

        fclose($fp);

        // ---------- RESUMEN POR PAÍS ----------
        $this->newLine();
        $this->line('<options=bold>RESUMEN POR PAÍS</> <comment>(ojo: cada moneda es distinta, no se suman entre sí)</comment>');
        $filasPais = [];
        foreach ($porPais as $pais => $d) {
            $filasPais[] = [
                $pais,
                number_format($d['creditos']),
                number_format($d['rem_actual'], 2),
                number_format($d['saldo_real'], 2),
                number_format($d['saldo_real'] - $d['rem_actual'], 2),
                $d['dudosos'] > 0 ? $d['dudosos'] : '-',
            ];
        }
        $this->table(
            ['País (moneda)', 'Créditos', 'Remaining hoy', 'Saldo real', 'Diferencia', 'A revisar'],
            $filasPais
        );

        // ---------- POR VENDEDOR ----------
        $this->newLine();
        $this->line('<options=bold>POR VENDEDOR</>');
        uasort($porVendedor, fn ($a, $b) => $b['creditos'] <=> $a['creditos']);
        $filasV = [];
        foreach ($porVendedor as $key => $d) {
            [$sid, $nombre] = explode('|', $key, 2);
            $filasV[] = [
                $sid, $nombre, $d['pais'],
                number_format(count($d['clientes'])),
                number_format($d['creditos']),
                number_format($d['rem_actual'], 2),
                number_format($d['saldo_real'], 2),
                $d['dudosos'] > 0 ? $d['dudosos'] : 'todos ok',
            ];
        }
        $this->table(
            ['seller_id', 'Vendedor', 'País', 'Clientes', 'Créditos', 'Remaining hoy', 'Saldo real', 'A revisar'],
            $filasV
        );

        // ---------- DETALLE CLIENTE POR CLIENTE ----------
        if ($this->option('seller-id')) {
            $this->detallePorCliente($filas, $threshold);
        }

        $this->newLine();
        $this->line('<options=bold>VERIFICACIÓN CRUZADA</>');
        $total = $confirmados + $dudosos;
        $this->line(sprintf(
            '  <fg=green>CONFIRMADOS</>: %s de %s (%.1f%%) — cuotas y pagos dicen lo mismo, migrar es seguro',
            number_format($confirmados), number_format($total), $total ? 100 * $confirmados / $total : 0
        ));
        if ($dudosos > 0) {
            $this->line(sprintf(
                '  <fg=yellow>A REVISAR</>:   %s — las dos vías no coinciden, mirar antes de migrar',
                number_format($dudosos)
            ));
            $this->line('  Para aislarlos: <comment>php artisan credits:affected-report --solo-descuadrados</comment>');
        }

        $this->newLine();
        $this->info("Listado completo: {$csvPath}");
        if (!$this->option('seller-id')) {
            $this->line('Detalle de una ruta: <comment>php artisan credits:affected-report --seller-id=N</comment>');
        }

        return self::SUCCESS;
    }

    /** Imprime vendedor → cliente → créditos para la ruta pedida. */
    private function detallePorCliente(array $filas, float $threshold): void
    {
        $porCliente = [];
        foreach ($filas as $f) {
            $porCliente[$f->cliente . ' (' . $f->documento . ')'][] = $f;
        }
        ksort($porCliente);

        $this->newLine();
        $this->line('<options=bold>DETALLE CLIENTE POR CLIENTE</>');

        foreach ($porCliente as $cliente => $creditos) {
            $this->newLine();
            $this->line("  <options=bold>{$cliente}</>");
            $tabla = [];
            foreach ($creditos as $f) {
                $totalCuotas = round((float) $f->total_cuotas, 2);
                $saldoA = round(max(0, (float) $f->deuda_cuotas - (float) $f->sin_aplicar), 2);
                $saldoB = round(max(0, $totalCuotas - (float) $f->pagos), 2);
                $coincide = abs($saldoA - $saldoB) <= $threshold;

                $tabla[] = [
                    '#00' . $f->id,
                    $f->status,
                    number_format($totalCuotas, 2),
                    number_format((float) $f->pagos, 2),
                    number_format((float) $f->sin_aplicar, 2),
                    number_format((float) $f->remaining_actual, 2),
                    number_format($saldoA, 2),
                    number_format($saldoB, 2),
                    $coincide ? 'ok' : 'REVISAR',
                ];
            }
            $this->table(
                ['Crédito', 'Status', 'Total', 'Pagado', 'Abono', 'Remaining hoy', 'Real (cuotas)', 'Real (pagos)', ''],
                $tabla
            );
        }
    }

    /**
     * Una sola consulta con agregados. Devuelve solo los créditos cuyo
     * remaining_amount no coincide con el saldo real.
     */
    private function consultar(float $threshold): array
    {
        $q = DB::table('credits as c')
            ->leftJoin('clients as cl', 'cl.id', '=', 'c.client_id')
            ->leftJoin('sellers as s', 's.id', '=', 'c.seller_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('cities as ci', 'ci.id', '=', 's.city_id')
            ->leftJoin('countries as co', 'co.id', '=', 'ci.country_id')
            ->leftJoin(DB::raw('(
                SELECT credit_id,
                       SUM(quota_amount) as total_cuotas,
                       SUM(quota_amount - paid_amount) as deuda_cuotas
                FROM installments WHERE deleted_at IS NULL GROUP BY credit_id
            ) as i'), 'i.credit_id', '=', 'c.id')
            ->leftJoin(DB::raw('(
                SELECT credit_id, COUNT(*) as npagos,
                       SUM(amount) as pagos,
                       SUM(unapplied_amount) as sin_aplicar
                FROM payments WHERE deleted_at IS NULL GROUP BY credit_id
            ) as p'), 'p.credit_id', '=', 'c.id')
            ->leftJoin(DB::raw('(
                SELECT p2.credit_id, SUM(pi.applied_amount) as aplicado
                FROM payment_installments pi
                INNER JOIN payments p2 ON p2.id = pi.payment_id AND p2.deleted_at IS NULL
                WHERE pi.deleted_at IS NULL GROUP BY p2.credit_id
            ) as a'), 'a.credit_id', '=', 'c.id')
            ->leftJoin(DB::raw('(
                SELECT credit_id, COUNT(*) as cuotas_borradas
                FROM installments WHERE deleted_at IS NOT NULL GROUP BY credit_id
            ) as b'), 'b.credit_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            ->whereRaw(
                'ABS(c.remaining_amount - GREATEST(COALESCE(i.deuda_cuotas,0) - COALESCE(p.sin_aplicar,0), 0)) > ?',
                [$threshold]
            );

        if ($sellerId = $this->option('seller-id')) {
            $q->where('c.seller_id', $sellerId);
        }
        if ($pais = $this->option('pais')) {
            $q->where('co.name', $pais);
        }
        if ($status = $this->option('status')) {
            $q->where('c.status', $status);
        }

        return $q->orderBy('co.name')
            ->orderBy('u.name')
            ->orderBy('cl.name')
            ->orderBy('c.id')
            ->select(
                'c.id', 'c.status', 'c.payment_frequency', 'c.total_amount',
                'c.remaining_amount as remaining_actual',
                'c.seller_id', 'c.client_id',
                'cl.name as cliente', 'cl.dni as documento',
                'u.name as vendedor',
                // Nada de '?' acá dentro: en un DB::raw se lo come el binder
                // de PDO y termina consumiendo los parámetros del where.
                DB::raw("COALESCE(co.name, 'Sin pais') as pais"),
                DB::raw("COALESCE(co.currency, 'S/M') as moneda"),
                DB::raw('COALESCE(i.total_cuotas, 0) as total_cuotas'),
                DB::raw('COALESCE(i.deuda_cuotas, 0) as deuda_cuotas'),
                DB::raw('COALESCE(p.npagos, 0) as npagos'),
                DB::raw('COALESCE(p.pagos, 0) as pagos'),
                DB::raw('COALESCE(p.sin_aplicar, 0) as sin_aplicar'),
                DB::raw('COALESCE(a.aplicado, 0) as aplicado'),
                DB::raw('COALESCE(b.cuotas_borradas, 0) as cuotas_borradas')
            )
            ->get()
            ->all();
    }
}
