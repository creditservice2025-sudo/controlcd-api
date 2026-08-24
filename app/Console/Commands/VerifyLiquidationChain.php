<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnóstico de la cadena de caja. SOLO LECTURA: no escribe una sola fila.
 *
 * La cadena tiene una invariante: el saldo inicial de un día es el importe a
 * entregar del día anterior. Nada la verificaba, así que se rompía en silencio.
 * Este comando la audita y saca los cuatro síntomas que producen descuadre:
 *
 *   1. Cadena rota      · initial_cash ≠ real_to_deliver del día previo.
 *   2. Plata sin caja   · días con movimientos y sin liquidación viva.
 *   3. Días dados de baja con movimientos y sin reemplazo (su importe quedó
 *      fuera de la cadena).
 *   4. Secuencia rota   · días sin aprobar con días posteriores ya aprobados
 *      (bloquean las consultas de todo el rango).
 *
 * Deliberadamente NO tiene --apply: reparar el histórico mueve saldos de
 * cientos de vendedores y va en su propia ventana, con respaldo y decisión
 * explícita. Acá se ve el tamaño del problema, no se toca.
 */
class VerifyLiquidationChain extends Command
{
    protected $signature = 'liquidations:verify-chain
        {--seller= : Limitar el diagnóstico a un vendedor}
        {--limit=25 : Máximo de filas a listar por sección}';

    protected $description = 'Verifica la integridad de la cadena de liquidaciones (solo lectura, no repara)';

    public function handle(): int
    {
        $seller = $this->option('seller') !== null ? (int) $this->option('seller') : null;
        $limit  = max(1, (int) $this->option('limit'));

        $this->info('Diagnóstico de la cadena de caja — SOLO LECTURA, no se escribe nada.');
        if ($seller) {
            $this->line("Alcance: vendedor #{$seller}");
        }
        $this->newLine();

        $hallazgos = 0;
        $hallazgos += $this->cadenaRota($seller, $limit);
        $hallazgos += $this->secuenciaRota($seller, $limit);
        $hallazgos += $this->diasBorradosConPlata($seller, $limit);
        $hallazgos += $this->plataSinCaja($seller, $limit);
        $hallazgos += $this->firmadosDesincronizados($seller, $limit);

        $this->newLine();
        if ($hallazgos === 0) {
            $this->info('Sin hallazgos: la cadena está íntegra.');
            return self::SUCCESS;
        }

        $this->warn("Total de hallazgos: {$hallazgos}. Este comando no repara nada.");

        // Exit 1 para que sirva de check en CI/monitoreo sin tener que parsear
        // la salida.
        return self::FAILURE;
    }

    /**
     * 1. initial_cash ≠ real_to_deliver del día previo vivo.
     */
    private function cadenaRota(?int $seller, int $limit): int
    {
        $filtro = $seller ? 'AND a.seller_id = ' . $seller : '';

        $filas = DB::select("
            SELECT a.seller_id, a.id, DATE(a.date) dia, a.initial_cash,
                   DATE(b.date) dia_previo, b.real_to_deliver previo,
                   ROUND(a.initial_cash - b.real_to_deliver, 2) diferencia
              FROM liquidations a
              JOIN liquidations b ON b.id = (
                   SELECT x.id FROM liquidations x
                    WHERE x.seller_id = a.seller_id AND x.date < a.date AND x.deleted_at IS NULL
                    ORDER BY x.date DESC LIMIT 1)
             WHERE a.deleted_at IS NULL
               AND ABS(a.initial_cash - b.real_to_deliver) > 0.01
               {$filtro}
             ORDER BY ABS(a.initial_cash - b.real_to_deliver) DESC
        ");

        $this->seccion('1. CADENA ROTA (el saldo inicial no es el cierre del día anterior)', count($filas));

        if (!$filas) {
            return 0;
        }

        $this->table(
            ['vendedor', 'liq', 'día', 'saldo inicial', 'día previo', 'cierre previo', 'diferencia'],
            collect($filas)->take($limit)->map(fn ($f) => [
                $f->seller_id, $f->id, $f->dia,
                number_format((float) $f->initial_cash, 2),
                $f->dia_previo,
                number_format((float) $f->previo, 2),
                number_format((float) $f->diferencia, 2),
            ])->all()
        );
        $this->truncado(count($filas), $limit);

        return count($filas);
    }

    /**
     * 4. Días sin aprobar con días posteriores ya aprobados.
     */
    private function secuenciaRota(?int $seller, int $limit): int
    {
        $filtro = $seller ? 'AND l.seller_id = ' . $seller : '';

        $filas = DB::select("
            SELECT l.seller_id, l.id, DATE(l.date) dia, l.status,
                   (SELECT COUNT(*) FROM liquidations p
                     WHERE p.seller_id = l.seller_id AND p.date > l.date
                       AND p.status = 'approved' AND p.deleted_at IS NULL) posteriores
              FROM liquidations l
             WHERE l.deleted_at IS NULL AND l.status <> 'approved' {$filtro}
            HAVING posteriores > 0
             ORDER BY posteriores DESC
        ");

        $this->seccion('2. SECUENCIA ROTA (día sin aprobar por debajo de días aprobados: bloquea consultas)', count($filas));

        if (!$filas) {
            return 0;
        }

        $this->table(
            ['vendedor', 'liq', 'día', 'estado', 'días posteriores aprobados'],
            collect($filas)->take($limit)->map(fn ($f) => [
                $f->seller_id, $f->id, $f->dia, $f->status, $f->posteriores,
            ])->all()
        );
        $this->truncado(count($filas), $limit);

        return count($filas);
    }

    /**
     * 3. Días dados de baja, sin fila viva que los reemplace, y con plata.
     */
    private function diasBorradosConPlata(?int $seller, int $limit): int
    {
        $query = DB::table('liquidations')->whereNotNull('deleted_at');
        if ($seller) {
            $query->where('seller_id', $seller);
        }

        $filas = [];
        $neto = 0.0;

        foreach ($query->orderBy('date')->get(['id', 'seller_id', 'date', 'status', 'deleted_at']) as $liq) {
            $dia = substr((string) $liq->date, 0, 10);

            $viva = DB::table('liquidations')
                ->where('seller_id', $liq->seller_id)
                ->whereDate('date', $dia)
                ->whereNull('deleted_at')
                ->exists();

            if ($viva) {
                continue; // hay otra fila ese día: la plata sigue contada
            }

            $mov = $this->movimientos((int) $liq->seller_id, $dia);

            if (abs($mov['neto']) < 0.01 && $mov['cobros'] < 0.01) {
                continue;
            }

            $neto += $mov['neto'];
            $filas[] = [
                $liq->seller_id, $liq->id, $dia, $liq->status, $liq->deleted_at,
                number_format($mov['cobros'], 2), number_format($mov['neto'], 2),
            ];
        }

        $this->seccion('3. DÍAS DADOS DE BAJA CON MOVIMIENTOS (su importe quedó fuera de la cadena)', count($filas));

        if (!$filas) {
            return 0;
        }

        $this->table(
            ['vendedor', 'liq', 'día', 'estado', 'dado de baja', 'cobros', 'neto fuera'],
            array_slice($filas, 0, $limit)
        );
        $this->truncado(count($filas), $limit);
        $this->warn('   Neto total fuera de la cadena: ' . number_format($neto, 2));

        return count($filas);
    }

    /**
     * 2. Días con movimientos que nunca tuvieron liquidación viva.
     */
    private function plataSinCaja(?int $seller, int $limit): int
    {
        $filtro = $seller ? 'AND c.seller_id = ' . $seller : '';
        $filtroU = $seller ? 'AND s.id = ' . $seller : '';

        // Solo COBROS: es el movimiento que representa plata que el cobrador
        // efectivamente recibió. Los días de puro desembolso sin caja suelen
        // ser importaciones de cartera y ensuciarían el diagnóstico.
        $filas = DB::select("
            SELECT c.seller_id, p.business_date dia, SUM(p.amount) cobros
              FROM payments p
              JOIN credits c ON c.id = p.credit_id
             WHERE p.deleted_at IS NULL AND p.business_date IS NOT NULL
               AND p.status IN ('Pagado','Aprobado','Abonado') {$filtro}
             GROUP BY c.seller_id, p.business_date
            HAVING NOT EXISTS (
                   SELECT 1 FROM liquidations l
                    WHERE l.seller_id = c.seller_id AND DATE(l.date) = p.business_date
                      AND l.deleted_at IS NULL)
             ORDER BY cobros DESC
        ");

        $this->seccion('4. COBROS SIN CAJA (días con recaudo y sin liquidación viva)', count($filas));

        if (!$filas) {
            return 0;
        }

        $total = array_sum(array_map(fn ($f) => (float) $f->cobros, $filas));

        $this->table(
            ['vendedor', 'día', 'cobros sin caja'],
            collect($filas)->take($limit)->map(fn ($f) => [
                $f->seller_id, $f->dia, number_format((float) $f->cobros, 2),
            ])->all()
        );
        $this->truncado(count($filas), $limit);
        $this->warn('   Cobros totales sin caja: ' . number_format($total, 2));

        return count($filas);
    }

    /**
     * 5. Días FIRMADOS cuyo recaudo guardado ya no coincide con sus pagos.
     *
     * Son los "Mono9 en potencia": días aprobados cuyos datos de origen se
     * movieron después de la firma (pagos borrados, business_date corregidos
     * por backfill). Antes del congelamiento, cualquier cascada que los
     * alcanzara los reescribía y le corría la caja al cobrador meses después.
     *
     * Ahora NO se reescriben —están sellados—, así que esto dejó de ser un
     * riesgo de descuadre y pasó a ser una discrepancia de reporte: la
     * liquidación dice una cosa y los movimientos dicen otra. Se muestra para
     * decidir caso por caso, no para corregir en masa.
     */
    private function firmadosDesincronizados(?int $seller, int $limit): int
    {
        $filtro = $seller ? 'AND l.seller_id = ' . $seller : '';

        $filas = DB::select("
            SELECT l.seller_id, l.currency,
                   COUNT(*) dias,
                   MIN(DATE(l.date)) desde,
                   MAX(DATE(l.date)) hasta,
                   ROUND(SUM(ABS(COALESCE(p.cobrado,0) - l.total_collected)), 2) desvio
              FROM liquidations l
              LEFT JOIN (
                   SELECT c.seller_id, pa.business_date d, SUM(pa.amount) cobrado
                     FROM payments pa
                     JOIN credits c ON c.id = pa.credit_id
                    WHERE pa.deleted_at IS NULL
                      AND pa.status IN ('Pagado','Aprobado','Abonado')
                    GROUP BY c.seller_id, pa.business_date
              ) p ON p.seller_id = l.seller_id AND p.d = DATE(l.date)
             WHERE l.deleted_at IS NULL AND l.status = 'approved'
               AND ABS(l.total_collected - COALESCE(p.cobrado,0)) > 0.01
               {$filtro}
             GROUP BY l.seller_id, l.currency
             ORDER BY dias DESC
        ");

        $totalDias = array_sum(array_map(fn ($f) => (int) $f->dias, $filas));

        $this->seccion(
            '5. DÍAS FIRMADOS DESINCRONIZADOS (su recaudo ya no coincide con sus pagos)',
            $totalDias
        );

        if (!$filas) {
            return 0;
        }

        $this->table(
            ['vendedor', 'moneda', 'días', 'desde', 'hasta', 'desvío acumulado'],
            collect($filas)->take($limit)->map(fn ($f) => [
                $f->seller_id, $f->currency, $f->dias, $f->desde, $f->hasta,
                number_format((float) $f->desvio, 2),
            ])->all()
        );
        $this->truncado(count($filas), $limit);
        $this->line('   Nota: el desvío NO se suma entre filas — hay varias monedas.');
        $this->line('   Estos días están SELLADOS: ninguna cascada los reescribe. Es discrepancia de reporte, no descuadre en curso.');

        return $totalDias;
    }

    /**
     * Movimientos reales de un día, con el mismo criterio de fecha que la
     * fórmula canónica.
     */
    private function movimientos(int $sellerId, string $dia): array
    {
        $cobros = (float) DB::table('payments')
            ->join('credits', 'credits.id', '=', 'payments.credit_id')
            ->where('credits.seller_id', $sellerId)
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $dia)
            ->whereIn('payments.status', ['Pagado', 'Aprobado', 'Abonado'])
            ->sum('payments.amount');

        $creditos = (float) DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereRaw('DATE(COALESCE(imported_at, created_at)) = ?', [$dia])
            ->sum('credit_value');

        $userId = DB::table('sellers')->where('id', $sellerId)->value('user_id');

        $ingresos = $userId ? (float) DB::table('incomes')
            ->where('user_id', $userId)->whereNull('deleted_at')
            ->where('business_date', $dia)->sum('value') : 0.0;

        $gastos = $userId ? (float) DB::table('expenses')
            ->where('user_id', $userId)->whereNull('deleted_at')
            ->where('business_date', $dia)->sum('value') : 0.0;

        return [
            'cobros' => $cobros,
            'neto'   => $cobros + $ingresos - $gastos - $creditos,
        ];
    }

    private function seccion(string $titulo, int $total): void
    {
        $this->newLine();
        if ($total === 0) {
            $this->info("{$titulo}: sin hallazgos");
            return;
        }
        $this->error("{$titulo}: {$total}");
    }

    private function truncado(int $total, int $limit): void
    {
        if ($total > $limit) {
            $this->line('   … y ' . ($total - $limit) . ' más (usá --limit para ver más).');
        }
    }
}
