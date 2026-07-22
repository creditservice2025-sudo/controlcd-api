<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionClient;
use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionLedger;
use App\Models\Collection\CollectionPayment;
use App\Models\Company;
use App\Models\User;
use App\Helpers\TimezoneHelper;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Registros diarios. No toca wallets ni ledger.
 * Es una bitácora manual paralela: ingreso | gasto | transferencia | ajuste.
 */
class CollectionDailyRecordService
{
    use ApiResponse;

    public function __construct(
        private readonly CollectionCashClosureService $closureSvc,
        private readonly CollectionWalletService $walletSvc,
    ) {
    }

    /**
     * Zona horaria (IANA) de la empresa. Deuda & Abono opera SIEMPRE en la hora
     * local de la empresa (companies.timezone), NO en la del navegador: así el
     * día del movimiento y el listado son consistentes (y coinciden con el
     * corte de caja automático). Fallback: America/Bogota.
     */
    private function companyTz(int $companyId): string
    {
        return Company::find($companyId)?->timezone ?: 'America/Bogota';
    }

    /**
     * Tendencia de movimientos día por día en un rango. Devuelve por cada
     * día: cobros, ingresos manuales, gastos aprobados, egresos manuales,
     * transferencias salientes, adiciones de capital y balance neto.
     *
     * Usado por el reporte Excel "tendencia mensual".
     */
    public function getTrend(int $companyId, string $from, string $to, string $tz, ?string $countryCode = null)
    {
        $tz = $this->companyTz($companyId);
        $bucket = $this->dailyBuckets($companyId, $from, $to, $tz, $countryCode);

        $totals = [
            'cobros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0,
            'egresos' => 0.0, 'transferencias' => 0.0,
            'adiciones' => 0.0, 'neto' => 0.0,
        ];
        foreach ($bucket as $row) {
            foreach ($totals as $k => $_) $totals[$k] += $row[$k];
        }
        foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

        return $this->successResponse([
            'from' => $from,
            'to' => $to,
            'timezone' => $tz,
            'country_code' => $countryCode,
            'days' => array_values($bucket),
            'totals' => $totals,
        ]);
    }

    /**
     * Calcula los buckets DIARIOS del flujo de caja en un rango, agrupando por
     * día contable (business_date). Fuente única usada por getTrend (tendencia)
     * y periodSummary (resumen por período con acumulado). Devuelve un array
     * keyed por 'YYYY-MM-DD' y ordenado ascendente. Cada fila:
     *   date, cobros, ingresos, gastos, egresos, transferencias, adiciones, neto
     * donde neto = cobros + ingresos − gastos − egresos − transferencias − adiciones
     * (el flujo de caja del día: lo que entra menos lo que sale de la caja).
     */
    private function dailyBuckets(int $companyId, string $from, string $to, string $tz, ?string $countryCode): array
    {
        $startUtc = Carbon::parse($from . ' 00:00:00', $tz)->utc();
        $endUtc = Carbon::parse($to . ' 23:59:59', $tz)->utc();

        // 1) Registros manuales por día contable y tipo.
        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        $drRows = $drQ
            ->selectRaw("to_char(business_date, 'YYYY-MM-DD') as d, type, SUM(amount) as total")
            ->groupBy('d', 'type')
            ->get();

        // 2) Cobros (payments) — recorded_at es timestamptz, se convierte a tz.
        $payQ = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$startUtc, $endUtc]);
        if ($countryCode) {
            $payQ->whereIn('credit_id', function ($sub) use ($companyId, $countryCode) {
                $sub->select('id')->from('collection_credits')
                    ->where('company_id', $companyId)
                    ->where('country_code', strtoupper($countryCode));
            });
        }
        $payRows = $payQ
            ->selectRaw("to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d, SUM(amount_paid) as total", [$tz])
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        // 3) Gastos aprobados (expenses) por día contable.
        $expRows = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->where('status', 'approved')
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw("to_char(business_date, 'YYYY-MM-DD') as d, SUM(amount) as total")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        // 4) Adiciones de capital por día contable.
        $adicQ = CollectionCapitalAddition::where('company_id', $companyId)
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) {
            $adicQ->whereIn('credit_id', function ($sub) use ($companyId, $countryCode) {
                $sub->select('id')->from('collection_credits')
                    ->where('company_id', $companyId)
                    ->where('country_code', strtoupper($countryCode));
            });
        }
        $adicRows = $adicQ
            ->selectRaw("to_char(business_date, 'YYYY-MM-DD') as d, SUM(amount) as total")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $bucket = [];
        $ensure = function (string $d) use (&$bucket) {
            if (!isset($bucket[$d])) {
                $bucket[$d] = [
                    'date' => $d,
                    'cobros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0,
                    'egresos' => 0.0, 'transferencias' => 0.0,
                    'adiciones' => 0.0, 'neto' => 0.0,
                ];
            }
            return $d;
        };

        foreach ($drRows as $r) {
            $d = $ensure($r->d);
            $amount = (float) $r->total;
            if ($r->type === 'ingreso') $bucket[$d]['ingresos'] += $amount;
            elseif ($r->type === 'gasto') $bucket[$d]['egresos'] += $amount;
            elseif ($r->type === 'transferencia') $bucket[$d]['transferencias'] += $amount;
        }
        foreach ($payRows as $d => $r) { $ensure($d); $bucket[$d]['cobros'] = (float) $r->total; }
        foreach ($expRows as $d => $r) { $ensure($d); $bucket[$d]['gastos'] = (float) $r->total; }
        foreach ($adicRows as $d => $r) { $ensure($d); $bucket[$d]['adiciones'] = (float) $r->total; }

        foreach ($bucket as $d => &$row) {
            $row['neto'] = round(
                $row['cobros'] + $row['ingresos']
                - $row['gastos'] - $row['egresos']
                - $row['transferencias'] - $row['adiciones'],
                2
            );
        }
        unset($row);
        ksort($bucket);

        return $bucket;
    }

    /**
     * Resumen del flujo de caja agrupado por período con SALDO ACUMULADO que
     * arrastra de un período al siguiente. Granularidades:
     *   daily | weekly | biweekly (quincena LatAm 1–15 / 16–fin) | monthly | yearly.
     *
     * El acumulado se siembra con el saldo de apertura (arrastre = cierre del
     * día anterior a `from`) y luego suma el neto de caja de cada período.
     */
    public function periodSummary(int $companyId, string $granularity, string $from, string $to, ?string $countryCode = null)
    {
        return $this->successResponse(
            $this->buildPeriodSummary($companyId, $granularity, $from, $to, $countryCode)
        );
    }

    /**
     * Construye el resumen por período (misma data que expone periodSummary).
     * Separado para reutilizarlo en el export PDF sin re-consultar la API.
     */
    public function buildPeriodSummary(int $companyId, string $granularity, string $from, string $to, ?string $countryCode = null): array
    {
        $tz = $this->companyTz($companyId);
        $allowed = ['daily', 'weekly', 'biweekly', 'monthly', 'yearly'];
        $granularity = in_array($granularity, $allowed, true) ? $granularity : 'daily';

        $daily = $this->dailyBuckets($companyId, $from, $to, $tz, $countryCode);

        // Saldo de apertura (arrastre) antes del rango.
        $opening = $this->closureSvc->getOpeningBalance($companyId, $from, $countryCode);

        // Roll-up de días → períodos.
        $periods = [];
        foreach ($daily as $date => $row) {
            [$key, $label, $pFrom, $pTo] = $this->periodBucket($date, $granularity);
            if (!isset($periods[$key])) {
                $periods[$key] = [
                    'key' => $key, 'label' => $label, 'from' => $pFrom, 'to' => $pTo,
                    'cobros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0,
                    'egresos' => 0.0, 'transferencias' => 0.0,
                    'adiciones' => 0.0, 'neto' => 0.0, 'acumulado' => 0.0,
                ];
            }
            foreach (['cobros', 'ingresos', 'gastos', 'egresos', 'transferencias', 'adiciones', 'neto'] as $f) {
                $periods[$key][$f] += $row[$f];
            }
        }
        ksort($periods);

        // Acumulado corrido, sembrado con el saldo de apertura.
        $acc = round($opening, 2);
        foreach ($periods as $k => &$p) {
            foreach (['cobros', 'ingresos', 'gastos', 'egresos', 'transferencias', 'adiciones', 'neto'] as $f) {
                $p[$f] = round($p[$f], 2);
            }
            $acc = round($acc + $p['neto'], 2);
            $p['acumulado'] = $acc;
        }
        unset($p);

        $totals = [
            'cobros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0,
            'egresos' => 0.0, 'transferencias' => 0.0, 'adiciones' => 0.0, 'neto' => 0.0,
        ];
        foreach ($periods as $p) {
            foreach ($totals as $k => $_) $totals[$k] += $p[$k];
        }
        foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

        return [
            'granularity' => $granularity,
            'from' => $from,
            'to' => $to,
            'timezone' => $tz,
            'country_code' => $countryCode,
            'opening_balance' => round($opening, 2),
            'closing_balance' => $acc,
            'buckets' => array_values($periods),
            'totals' => $totals,
        ];
    }

    /**
     * Dada una fecha 'YYYY-MM-DD' y una granularidad, devuelve
     * [clave, etiqueta, desde, hasta] del período al que pertenece.
     * Las claves son ordenables cronológicamente como string.
     */
    private function periodBucket(string $date, string $granularity): array
    {
        $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $c = Carbon::parse($date);

        switch ($granularity) {
            case 'weekly':
                $s = $c->copy()->startOfWeek(Carbon::MONDAY);
                $e = $c->copy()->endOfWeek(Carbon::SUNDAY);
                return [
                    $s->format('o-\WW'),
                    'Semana ' . $s->format('d/m') . ' – ' . $e->format('d/m/Y'),
                    $s->toDateString(), $e->toDateString(),
                ];
            case 'biweekly':
                if ($c->day <= 15) {
                    $s = $c->copy()->day(1); $e = $c->copy()->day(15); $q = '1ª';
                } else {
                    $s = $c->copy()->day(16); $e = $c->copy()->endOfMonth(); $q = '2ª';
                }
                return [
                    $c->format('Y-m') . ($c->day <= 15 ? '-Q1' : '-Q2'),
                    $q . ' quincena ' . $meses[(int) $c->format('n')] . ' ' . $c->format('Y'),
                    $s->toDateString(), $e->toDateString(),
                ];
            case 'monthly':
                $s = $c->copy()->startOfMonth();
                $e = $c->copy()->endOfMonth();
                return [
                    $c->format('Y-m'),
                    $meses[(int) $c->format('n')] . ' ' . $c->format('Y'),
                    $s->toDateString(), $e->toDateString(),
                ];
            case 'yearly':
                return [
                    $c->format('Y'),
                    $c->format('Y'),
                    $c->copy()->startOfYear()->toDateString(),
                    $c->copy()->endOfYear()->toDateString(),
                ];
            case 'daily':
            default:
                return [$date, $c->format('d/m/Y'), $date, $date];
        }
    }

    /**
     * Comparativo del período actual vs el anterior (mensual o anual) para
     * ingresos, gastos y neto, con delta y % de variación. Compara "a la fecha"
     * (MTD/YTD) contra el mismo tramo del período anterior, para que sea justo.
     */
    public function periodComparison(int $companyId, string $granularity, ?string $countryCode = null)
    {
        $tz = $this->companyTz($companyId);
        $now = Carbon::now($tz);
        $granularity = in_array($granularity, ['monthly', 'yearly'], true) ? $granularity : 'monthly';

        if ($granularity === 'yearly') {
            $currFrom = $now->copy()->startOfYear()->toDateString();
            $currTo = $now->toDateString();
            $prevRef = $now->copy()->subYear();
            $prevFrom = $prevRef->copy()->startOfYear()->toDateString();
            $prevTo = $prevRef->toDateString();
        } else {
            $currFrom = $now->copy()->startOfMonth()->toDateString();
            $currTo = $now->toDateString();
            $prevRef = $now->copy()->subMonthNoOverflow();
            $prevFrom = $prevRef->copy()->startOfMonth()->toDateString();
            $prevTo = $prevRef->toDateString();
        }

        $curr = $this->rangeTotals($companyId, $currFrom, $currTo, $tz, $countryCode);
        $prev = $this->rangeTotals($companyId, $prevFrom, $prevTo, $tz, $countryCode);

        $metrics = [];
        foreach (['ingresos', 'gastos', 'neto'] as $k) {
            $c = $curr[$k];
            $p = $prev[$k];
            $delta = round($c - $p, 2);
            $pct = ($p != 0.0) ? round(($c - $p) / abs($p) * 100, 1) : null;
            $metrics[] = [
                'key' => $k,
                'current' => $c,
                'previous' => $p,
                'delta' => $delta,
                'pct' => $pct,
            ];
        }

        return $this->successResponse([
            'granularity' => $granularity,
            'current' => ['from' => $currFrom, 'to' => $currTo],
            'previous' => ['from' => $prevFrom, 'to' => $prevTo],
            'metrics' => $metrics,
        ]);
    }

    /**
     * Totales de flujo de caja de un rango (ingresos, gastos, neto), sumando los
     * buckets diarios. Reutilizado por el comparativo.
     */
    private function rangeTotals(int $companyId, string $from, string $to, string $tz, ?string $countryCode): array
    {
        $buckets = $this->dailyBuckets($companyId, $from, $to, $tz, $countryCode);
        $sum = ['cobros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0, 'egresos' => 0.0, 'transferencias' => 0.0, 'adiciones' => 0.0, 'neto' => 0.0];
        foreach ($buckets as $b) {
            foreach ($sum as $k => $_) $sum[$k] += $b[$k];
        }
        return [
            'ingresos' => round($sum['cobros'] + $sum['ingresos'], 2),
            'gastos' => round($sum['gastos'] + $sum['egresos'] + $sum['transferencias'] + $sum['adiciones'], 2),
            'neto' => round($sum['neto'], 2),
        ];
    }

    /**
     * Búsqueda de movimientos manuales por rango + filtros: texto (descripción/
     * categoría), tipo, categoría, rango de montos. Devuelve hasta 300 resultados
     * (con bandera de truncado) ordenados del más reciente al más antiguo.
     */
    public function search($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $tz = $this->companyTz($companyId);
        $from = $request->query('from', Carbon::now($tz)->startOfMonth()->toDateString());
        $to = $request->query('to', Carbon::now($tz)->toDateString());
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $category = trim((string) $request->query('category', ''));
        $minAmount = $request->query('min_amount');
        $maxAmount = $request->query('max_amount');
        $countryCode = $request->query('country_code');

        $query = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $query->where('country_code', strtoupper($countryCode));
        if ($type && in_array($type, CollectionDailyRecord::TYPES)) $query->where('type', $type);
        if ($category !== '') $query->where('category', 'ilike', '%' . $category . '%');
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('description', 'ilike', '%' . $q . '%')
                    ->orWhere('category', 'ilike', '%' . $q . '%');
            });
        }
        if (is_numeric($minAmount)) $query->where('amount', '>=', (float) $minAmount);
        if (is_numeric($maxAmount)) $query->where('amount', '<=', (float) $maxAmount);

        $limit = 300;
        $total = (clone $query)->count();
        $records = $query
            ->orderBy('business_date', 'desc')->orderBy('recorded_at', 'desc')
            ->limit($limit)->get();

        $uids = $records->pluck('user_id')->filter()->unique()->values()->all();
        if (!empty($uids)) {
            $names = \App\Models\User::whereIn('id', $uids)->pluck('name', 'id');
            $records->each(function ($r) use ($names) {
                $r->created_by_name = $names[$r->user_id] ?? null;
            });
        }

        return $this->successResponse([
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'count' => $records->count(),
            'truncated' => $total > $limit,
            'records' => $records->values(),
        ]);
    }

    /**
     * Gastos agrupados por categoría en un rango (para el reporte "gastos por
     * categoría"). Combina egresos manuales (daily_records type=gasto) y gastos
     * aprobados (expenses), ambos por fecha contable. Devuelve total, conteo y
     * porcentaje por categoría, ordenado de mayor a menor.
     */
    public function expensesByCategory(int $companyId, string $from, string $to, ?string $countryCode = null)
    {
        $map = $this->spentByCategoryMap($companyId, $from, $to, $countryCode);
        $total = array_sum(array_column($map, 'total'));
        $cats = array_values($map);
        usort($cats, fn ($a, $b) => $b['total'] <=> $a['total']);
        foreach ($cats as &$c) {
            $c['total'] = round($c['total'], 2);
            $c['pct'] = $total > 0 ? round($c['total'] / $total * 100, 1) : 0.0;
        }
        unset($c);

        return $this->successResponse([
            'from' => $from,
            'to' => $to,
            'country_code' => $countryCode,
            'total' => round($total, 2),
            'categories' => $cats,
        ]);
    }

    /**
     * Mapa categoría → ['category','total','count'] del gasto en un rango.
     * Combina egresos manuales (daily_records gasto) y gastos aprobados
     * (expenses), unificando los códigos de expenses a etiquetas en español.
     */
    private function spentByCategoryMap(int $companyId, string $from, string $to, ?string $countryCode): array
    {
        $expLabels = [
            'fuel' => 'Combustible', 'food' => 'Alimentación', 'toll' => 'Peaje',
            'maintenance' => 'Mantenimiento', 'other' => 'Otro',
        ];

        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('type', 'gasto')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        $drRows = $drQ
            ->selectRaw("COALESCE(NULLIF(category, ''), 'Sin categoría') as cat, SUM(amount) as total, COUNT(*) as cnt")
            ->groupBy('cat')->get();

        $expRows = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->where('status', 'approved')
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw("COALESCE(NULLIF(category, ''), 'Sin categoría') as cat, SUM(amount) as total, COUNT(*) as cnt")
            ->groupBy('cat')->get();

        $map = [];
        $add = function ($cat, $total, $cnt) use (&$map) {
            $map[$cat] = $map[$cat] ?? ['category' => $cat, 'total' => 0.0, 'count' => 0];
            $map[$cat]['total'] += (float) $total;
            $map[$cat]['count'] += (int) $cnt;
        };
        foreach ($drRows as $r) $add($r->cat, $r->total, $r->cnt);
        foreach ($expRows as $r) $add($expLabels[$r->cat] ?? $r->cat, $r->total, $r->cnt);

        return $map;
    }

    /**
     * Lista de presupuestos por categoría de la empresa.
     */
    public function listBudgets(int $companyId)
    {
        $budgets = \App\Models\Collection\CollectionCategoryBudget::where('company_id', $companyId)
            ->orderBy('category')->get(['id', 'category', 'amount', 'currency']);
        return $this->successResponse(['budgets' => $budgets]);
    }

    /**
     * Crea o actualiza el presupuesto mensual de una categoría (upsert por
     * company + category).
     */
    public function upsertBudget(int $companyId, string $category, float $amount, ?string $currency, ?int $userId)
    {
        $category = trim($category);
        if ($category === '') return $this->errorResponse('Categoría requerida', 422);
        if ($amount <= 0) return $this->errorResponse('El monto debe ser mayor a 0', 422);

        $budget = \App\Models\Collection\CollectionCategoryBudget::updateOrCreate(
            ['company_id' => $companyId, 'category' => $category],
            ['amount' => $amount, 'currency' => $currency ? strtoupper($currency) : null, 'created_by' => $userId]
        );
        return $this->successResponse(['budget' => $budget, 'message' => 'Presupuesto guardado']);
    }

    /**
     * Elimina un presupuesto de la empresa.
     */
    public function deleteBudget(int $companyId, int $id)
    {
        $budget = \App\Models\Collection\CollectionCategoryBudget::where('company_id', $companyId)->find($id);
        if (!$budget) return $this->errorResponse('Presupuesto no encontrado', 404);
        $budget->delete();
        return $this->successResponse(['success' => true, 'message' => 'Presupuesto eliminado']);
    }

    /**
     * Estado de los presupuestos del mes en curso: gastado vs tope, %, restante
     * y semáforo (ok < 80% ≤ warning < 100% ≤ over).
     */
    public function budgetStatus(int $companyId, ?string $countryCode = null)
    {
        $tz = $this->companyTz($companyId);
        $now = Carbon::now($tz);
        $from = $now->copy()->startOfMonth()->toDateString();
        $to = $now->toDateString();

        $spent = $this->spentByCategoryMap($companyId, $from, $to, $countryCode);
        $budgets = \App\Models\Collection\CollectionCategoryBudget::where('company_id', $companyId)
            ->orderBy('category')->get();

        $rows = [];
        foreach ($budgets as $b) {
            $amount = (float) $b->amount;
            $used = (float) ($spent[$b->category]['total'] ?? 0.0);
            $pct = $amount > 0 ? round($used / $amount * 100, 1) : 0.0;
            $status = $pct >= 100 ? 'over' : ($pct >= 80 ? 'warning' : 'ok');
            $rows[] = [
                'id' => $b->id,
                'category' => $b->category,
                'amount' => round($amount, 2),
                'spent' => round($used, 2),
                'remaining' => round($amount - $used, 2),
                'pct' => $pct,
                'status' => $status,
            ];
        }

        return $this->successResponse([
            'month' => $now->format('Y-m'),
            'from' => $from,
            'to' => $to,
            'budgets' => $rows,
        ]);
    }

    /**
     * Genera el PDF del resumen por período con saldo acumulado (DomPDF).
     */
    public function downloadPeriodSummaryPdf(int $companyId, string $granularity, string $from, string $to, ?string $countryCode, ?string $currency)
    {
        $data = $this->buildPeriodSummary($companyId, $granularity, $from, $to, $countryCode);
        $company = Company::find($companyId);
        $tz = $data['timezone'];

        $curr = strtoupper($currency ?: 'COP');
        $money = fn ($v) => $curr . ' ' . number_format((float) $v, 2, ',', '.');

        $labels = [
            'daily' => 'Diario', 'weekly' => 'Semanal', 'biweekly' => 'Quincenal',
            'monthly' => 'Mensual', 'yearly' => 'Anual',
        ];

        // Movimientos manuales del rango, agrupados por bucket para el detalle.
        $movsQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $movsQ->where('country_code', strtoupper($countryCode));
        $movs = $movsQ->orderBy('business_date', 'desc')->orderBy('recorded_at', 'desc')->get();

        $mUids = $movs->pluck('user_id')->filter()->unique()->values()->all();
        $mNames = !empty($mUids)
            ? \App\Models\User::whereIn('id', $mUids)->pluck('name', 'id')
            : collect();

        $typeLabels = ['ingreso' => 'Ingreso', 'gasto' => 'Gasto', 'transferencia' => 'Transferencia'];
        $movsByBucket = [];
        foreach ($movs as $m) {
            if (!$m->business_date) continue;
            $bd = Carbon::parse($m->business_date)->format('Y-m-d');
            [$bk] = $this->periodBucket($bd, $granularity);
            $sign = $m->type === 'ingreso' ? '+' : ($m->type === 'gasto' ? '-' : '');
            $cls = $m->type === 'ingreso' ? 'pos' : ($m->type === 'gasto' ? 'neg' : 'transf');
            $movsByBucket[$bk][] = [
                'date' => Carbon::parse($bd)->format('d/m/Y')
                    . ($m->recorded_at ? ' ' . Carbon::parse($m->recorded_at)->format('H:i:s') : ''),
                'type' => ($typeLabels[$m->type] ?? $m->type) . ($m->category ? ' · ' . $m->category : ''),
                'desc' => $m->description ?: '—',
                'author' => $m->user_id ? ($mNames[$m->user_id] ?? '—') : '—',
                'amount' => $sign . $money($m->amount),
                'cls' => $cls,
            ];
        }

        $rows = array_map(function ($b) use ($money, $movsByBucket) {
            return [
                'label' => $b['label'],
                'range' => ($b['from'] === $b['to'])
                    ? ''
                    : (Carbon::parse($b['from'])->format('d/m/Y') . ' – ' . Carbon::parse($b['to'])->format('d/m/Y')),
                'ingresos' => $money($b['cobros'] + $b['ingresos']),
                'gastos' => $money($b['gastos'] + $b['egresos'] + $b['transferencias'] + $b['adiciones']),
                'neto' => $money($b['neto']),
                'neto_neg' => $b['neto'] < 0,
                'acumulado' => $money($b['acumulado']),
                'acum_neg' => $b['acumulado'] < 0,
                'movements' => $movsByBucket[$b['key']] ?? [],
            ];
        }, $data['buckets']);

        $t = $data['totals'];
        $viewData = [
            'companyName' => $company->name ?? 'Empresa',
            'granularityLabel' => $labels[$granularity] ?? 'Diario',
            'rangeLabel' => Carbon::parse($from)->format('d/m/Y') . ' – ' . Carbon::parse($to)->format('d/m/Y'),
            'countryCode' => $countryCode,
            'currency' => $curr,
            'reportDate' => Carbon::now($tz)->format('d/m/Y H:i:s'),
            'openingBalance' => $money($data['opening_balance']),
            'hasOpening' => (float) $data['opening_balance'] != 0.0,
            'closingBalance' => $money($data['closing_balance']),
            'closingNeg' => $data['closing_balance'] < 0,
            'rows' => $rows,
            'totalIngresos' => $money($t['cobros'] + $t['ingresos']),
            'totalGastos' => $money($t['gastos'] + $t['egresos'] + $t['transferencias'] + $t['adiciones']),
            'totalNeto' => $money($t['neto']),
        ];

        $pdf = app('dompdf.wrapper');
        $pdf->loadView('collection.period-summary', $viewData);
        $pdf->setPaper('a4', 'portrait');

        $safe = fn ($s) => preg_replace('/[^A-Za-z0-9_\-]/', '_', trim((string) $s));
        $fileName = 'saldo-acumulado_' . $safe($company->name ?? 'empresa')
            . '_' . $granularity . '_' . Carbon::now($tz)->format('Y-m-d') . '.pdf';

        return response()->make($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /**
     * Detalle de movimientos manuales (ingreso/gasto/transferencia) en un RANGO
     * de fecha contable [from, to]. Usado por el drill-down del resumen por
     * período: al hacer clic en un bucket se listan sus movimientos.
     */
    public function rangeDetail($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $from = $request->query('from');
        $to = $request->query('to');
        if (!$from || !$to) return $this->errorResponse('Rango (from, to) requerido', 422);

        $countryCode = $request->query('country_code');
        $type = $request->query('type');

        $q = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $q->where('country_code', strtoupper($countryCode));
        if ($type && in_array($type, CollectionDailyRecord::TYPES)) $q->where('type', $type);

        $records = $q->orderBy('business_date', 'desc')->orderBy('recorded_at', 'desc')->get();

        // Nombre del creador (usuarios en MySQL, registros en PostgreSQL).
        $userIds = $records->pluck('user_id')->filter()->unique()->values()->all();
        if (!empty($userIds)) {
            $names = \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');
            $records->each(function ($r) use ($names) {
                $r->created_by_name = $names[$r->user_id] ?? null;
            });
        }

        return $this->successResponse([
            'from' => $from,
            'to' => $to,
            'count' => $records->count(),
            'records' => $records->values(),
        ]);
    }

    public function index($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $tz = $this->companyTz($companyId);
        $date = $request->query('date', Carbon::now($tz)->toDateString());
        $countryCode = $request->query('country_code');
        $type = $request->query('type');

        // Si el tipo filtrado es 'capital_addition', no cargamos daily records.
        $dailyRecords = collect();
        if ($type !== 'capital_addition') {
            $q = CollectionDailyRecord::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('business_date', $date);

            if ($countryCode) $q->where('country_code', strtoupper($countryCode));
            if ($type && in_array($type, CollectionDailyRecord::TYPES)) $q->where('type', $type);

            $dailyRecords = $q->orderBy('recorded_at', 'desc')->get();

            // Adjuntar el nombre de quien creó cada movimiento para trazabilidad.
            // Los usuarios viven en MySQL y los registros en PostgreSQL, por eso
            // se resuelven en un query aparte (batch por user_id).
            $userIds = $dailyRecords->pluck('user_id')->filter()->unique()->values()->all();
            if (!empty($userIds)) {
                $names = \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');
                $dailyRecords->each(function ($r) use ($names) {
                    $r->created_by_name = $names[$r->user_id] ?? null;
                });
            }

            // Distintivo "editado" (estilo core bancario): conteo de ajustes y
            // datos del último (quién/cuándo), leídos de la auditoría en batch.
            $recordIds = $dailyRecords->pluck('id')->filter()->values()->all();
            if (!empty($recordIds)) {
                $audits = \App\Models\Collection\CollectionDailyRecordAudit::whereIn('daily_record_id', $recordIds)
                    ->orderByDesc('created_at')
                    ->get(['daily_record_id', 'user_id', 'created_at']);
                $byRecord = $audits->groupBy(fn($a) => (int) $a->daily_record_id);
                $editorIds = $audits->pluck('user_id')->filter()->unique()->values()->all();
                $editorNames = !empty($editorIds)
                    ? \App\Models\User::whereIn('id', $editorIds)->pluck('name', 'id')
                    : collect();
                $dailyRecords->each(function ($r) use ($byRecord, $editorNames, $tz) {
                    $list = $byRecord->get((int) $r->id);
                    $r->edit_count = $list ? $list->count() : 0;
                    if ($list && $list->count()) {
                        $last = $list->first(); // ya ordenado desc por created_at
                        $rtz = TimezoneHelper::timezoneForCountryCode($r->country_code) ?: $tz;
                        $r->last_edited_at_label = $last->created_at
                            ? Carbon::parse($last->created_at)->setTimezone($rtz)->format('d/m/Y H:i:s')
                            : null;
                        $r->last_edited_by_name = $editorNames[$last->user_id] ?? null;
                    } else {
                        $r->last_edited_at_label = null;
                        $r->last_edited_by_name = null;
                    }
                });
            }
        }

        // Adiciones de capital como filas virtuales (type='capital_addition').
        $capitalVirtual = collect();
        if (!$type || $type === 'capital_addition') {
            $capitalVirtual = $this->buildVirtualCapitalAdditions($companyId, $date, $countryCode);
        }

        // Combinar y ordenar por timestamp descendente.
        $all = $dailyRecords->concat($capitalVirtual)->sortByDesc(function ($r) {
            return is_object($r) && isset($r->recorded_at)
                ? Carbon::parse($r->recorded_at)->timestamp
                : (is_array($r) ? strtotime($r['recorded_at'] ?? 'now') : 0);
        })->values();

        $totals = [
            'ingreso' => (float) $dailyRecords->where('type', 'ingreso')->sum('amount'),
            'gasto' => (float) $dailyRecords->where('type', 'gasto')->sum('amount'),
            'transferencia' => (float) $dailyRecords->where('type', 'transferencia')->sum('amount'),
            'capital_addition' => (float) $capitalVirtual->sum(fn($r) => (float) ($r['amount'] ?? 0)),
        ];
        // Balance del día = ingresos − (gastos + transferencias salientes + adiciones de capital).
        // Las transferencias salen de la wallet (action=transfer_out) y las adiciones
        // también son salidas hacia el cliente, por eso restan al balance del día.
        $totals['net'] = $totals['ingreso']
            - $totals['gasto']
            - $totals['transferencia']
            - $totals['capital_addition'];

        // Movimientos eliminados (soft-deleted) del día, solo si se piden.
        // Se devuelven aparte: NO entran en los totales ni en el corte, son
        // informativos (trail de auditoría: quién eliminó y cuándo).
        $deleted = collect();
        $includeDeleted = filter_var($request->query('include_deleted', false), FILTER_VALIDATE_BOOLEAN);
        if ($includeDeleted && $type !== 'capital_addition') {
            $dq = CollectionDailyRecord::onlyTrashed()
                ->where('company_id', $companyId)
                ->where('business_date', $date);
            if ($countryCode) $dq->where('country_code', strtoupper($countryCode));
            if ($type && in_array($type, CollectionDailyRecord::TYPES)) $dq->where('type', $type);
            $deleted = $dq->orderByDesc('deleted_at')->get();

            // Nombres de creador y de quien eliminó (usuarios viven en MySQL).
            $uids = $deleted->pluck('user_id')
                ->merge($deleted->pluck('deleted_by'))
                ->filter()->unique()->values()->all();
            $names = !empty($uids)
                ? \App\Models\User::whereIn('id', $uids)->pluck('name', 'id')
                : collect();

            $deleted->each(function ($r) use ($names, $tz) {
                $r->created_by_name = $names[$r->user_id] ?? null;
                $r->deleted_by_name = $r->deleted_by ? ($names[$r->deleted_by] ?? null) : null;
                $r->is_deleted = true;
                // deleted_at se guardó en zona de la app; se muestra en la zona
                // del país del movimiento (o de la empresa como fallback).
                $rtz = TimezoneHelper::timezoneForCountryCode($r->country_code) ?: $tz;
                $r->deleted_at_label = $r->deleted_at
                    ? Carbon::parse($r->deleted_at)->setTimezone($rtz)->format('d/m/Y H:i:s')
                    : null;
            });
        }

        return $this->successResponse([
            'records' => $all,
            'summary' => $totals,
            'deleted' => $deleted->values(),
            'date' => $date,
            'timezone' => $tz,
        ]);
    }

    /**
     * Construye filas virtuales de tipo 'capital_addition' para el dia dado,
     * compatibles con la forma de CollectionDailyRecord que consume la UI.
     */
    private function buildVirtualCapitalAdditions(int $companyId, string $date, ?string $countryCode): \Illuminate\Support\Collection
    {
        $rows = CollectionCapitalAddition::query()
            ->where('company_id', $companyId)
            ->where('business_date', $date)
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) return collect();

        $creditIds = $rows->pluck('credit_id')->filter()->unique()->values()->all();
        $credits = CollectionCredit::query()
            ->whereIn('id', $creditIds)
            ->where('company_id', $companyId)
            ->get(['id', 'client_id', 'amount'])
            ->keyBy('id');

        $clientIds = $credits->pluck('client_id')->filter()->unique()->values()->all();
        $clients = CollectionClient::query()
            ->whereIn('id', $clientIds)
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'country_code'])
            ->keyBy('id');

        // Si se filtra por country_code, aplicarlo al cliente vinculado.
        if ($countryCode) {
            $cc = strtoupper($countryCode);
            $rows = $rows->filter(function ($add) use ($credits, $clients, $cc) {
                $credit = $credits->get($add->credit_id);
                if (!$credit) return false;
                $client = $clients->get($credit->client_id);
                if (!$client) return false;
                return strtoupper((string) $client->country_code) === $cc;
            })->values();
        }

        $userIds = $rows->pluck('created_by')->filter()->unique()->values()->all();
        $users = !empty($userIds)
            ? User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all()
            : [];

        return $rows->map(function ($add) use ($credits, $clients, $users) {
            $credit = $credits->get($add->credit_id);
            $client = $credit ? $clients->get($credit->client_id) : null;
            $clientName = $client->name ?? null;
            $countryCode = $client->country_code ?? null;

            $meta = [
                'credit_id' => $add->credit_id,
                'client_name' => $clientName,
                'reference_number' => $add->reference_number,
                'bank_name' => $add->bank_name,
                'payment_method' => $add->payment_method,
                'voucher_photo' => $add->voucher_photo,
                'virtual' => true,
            ];

            return [
                'id' => 'ca_' . $add->id,
                'company_id' => $add->company_id,
                'user_id' => $add->created_by,
                'user_name' => $users[$add->created_by] ?? null,
                'type' => 'capital_addition',
                'category' => $clientName
                    ? ('Crédito #' . $add->credit_id . ' · ' . $clientName)
                    : ('Crédito #' . $add->credit_id),
                'amount' => (float) $add->amount,
                'currency' => 'COP',
                'country_code' => $countryCode,
                'description' => $add->notes,
                'recorded_at' => optional($add->created_at)->toISOString()
                    ?? ($add->business_date . 'T00:00:00.000Z'),
                'latitude' => null,
                'longitude' => null,
                'metadata' => $meta,
                'deleted_at' => null,
            ];
        })->values();
    }

    public function create($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $validated = $request->validate([
            'type' => 'required|string|in:' . implode(',', CollectionDailyRecord::TYPES),
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'country_code' => 'nullable|string|size:2',
            'category' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            // Hasta 3 evidencias por registro
            'evidence' => 'nullable|array|max:3',
            'evidence.*' => 'image|max:5120',
            'recorded_at' => 'nullable|date',
            'transfer_from' => 'nullable|string|max:100',
            'transfer_to' => 'nullable|string|max:100',
        ]);

        // Fecha contable (business_date): anclada a la zona del PAÍS del
        // movimiento (country_code → IANA); si no hay país conocido, cae a la
        // zona de la empresa. El recorded_at llega como reloj de pared local en
        // esa misma zona. business_date se congela aquí y es la base de todos
        // los cortes/reportes (no se re-deriva en consulta).
        $countryCode = !empty($validated['country_code']) ? strtoupper($validated['country_code']) : null;
        $tz = TimezoneHelper::timezoneForCountryCode($countryCode) ?: $this->companyTz($companyId);
        $recordedAt = !empty($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'], $tz)
            : Carbon::now($tz);
        $businessDate = $recordedAt->copy()->toDateString();
        if ($this->closureSvc->isDayClosed($companyId, $businessDate)) {
            return $this->errorResponse(
                'No se pueden registrar movimientos: la caja del día ' . $businessDate . ' está cerrada. El corte del día ya es definitivo.',
                409
            );
        }

        $metadata = [];
        $evidencePaths = [];
        if ($request->hasFile('evidence')) {
            $files = $request->file('evidence');
            if (!is_array($files)) $files = [$files];
            foreach (array_slice($files, 0, 3) as $file) {
                $path = $file->store(
                    "collection/daily-records/evidence/{$companyId}",
                    'public'
                );
                $evidencePaths[] = $path;
            }
        }
        if (!empty($evidencePaths)) $metadata['evidence_paths'] = $evidencePaths;
        // Para transferencias el origen es SIEMPRE la wallet del módulo.
        // El payload solo necesita destino. Forzamos transfer_from = "Wallet".
        if ($validated['type'] === 'transferencia') {
            $metadata['transfer_from'] = 'Wallet del módulo';
            if (!empty($validated['transfer_to'])) {
                $metadata['transfer_to'] = $validated['transfer_to'];
            }
        } else {
            if (!empty($validated['transfer_from'])) $metadata['transfer_from'] = $validated['transfer_from'];
            if (!empty($validated['transfer_to'])) $metadata['transfer_to'] = $validated['transfer_to'];
        }

        return DB::connection('collection_pgsql')->transaction(function () use ($validated, $companyId, $metadata, $recordedAt, $businessDate, $countryCode) {
            $currency = strtoupper($validated['currency']);

            $record = CollectionDailyRecord::create([
                'company_id' => $companyId,
                'user_id' => Auth::id(),
                'type' => $validated['type'],
                'category' => $validated['category'] ?? null,
                'amount' => $validated['amount'],
                'currency' => $currency,
                'country_code' => $countryCode,
                'description' => $validated['description'] ?? null,
                // Se guarda en UTC (convención del módulo, igual que pagos): el
                // reloj de pared se parseó en la zona del país y aquí se
                // convierte al instante UTC correspondiente.
                'recorded_at' => $recordedAt->copy()->utc(),
                // Día contable congelado (zona del país). Base de cortes/reportes.
                'business_date' => $businessDate,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'metadata' => $metadata,
            ]);

            // Si es transferencia: descontar de la wallet del módulo y dejar
            // un movimiento en el ledger (action=transfer_out) referenciando
            // el daily_record. La wallet se elige por currency+country_code.
            if ($validated['type'] === 'transferencia') {
                $destino = $metadata['transfer_to'] ?? 'cuenta destino';
                $this->walletSvc->recordMovement([
                    'company_id' => $companyId,
                    'currency' => $currency,
                    'country_code' => $countryCode ?? 'CO',
                    'amount' => $validated['amount'],
                    'type' => 'debit',
                    'action_type' => 'transfer_out',
                    'reference_type' => 'daily_record',
                    'reference_id' => $record->id,
                    'description' => "Transferencia a {$destino}"
                        . (!empty($validated['description']) ? ' — ' . $validated['description'] : ''),
                ]);
            }

            return $this->successCreatedResponse([
                'success' => true,
                'message' => 'Registro creado correctamente',
                'data' => $record,
            ]);
        });
    }

    public function destroy($request, int $id)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $record = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->find($id);

        if (!$record) return $this->errorResponse('Registro no encontrado', 404);

        // Bloquear si el día del registro está cerrado.
        $tz = $this->companyTz($companyId);
        $recordDate = optional($record->recorded_at)->setTimezone($tz)->toDateString();
        if ($recordDate && $this->closureSvc->isDayClosed($companyId, $recordDate)) {
            return $this->errorResponse(
                'No se puede eliminar: el día ' . $recordDate . ' tiene la caja cerrada. El corte del día ya es definitivo.',
                409
            );
        }

        return DB::connection('collection_pgsql')->transaction(function () use ($record) {
            // Si era transferencia, revertir el movimiento en la wallet
            // (re-acreditando el monto que se debitó al crearla).
            if ($record->type === 'transferencia') {
                $meta = is_array($record->metadata) ? $record->metadata : [];
                $destino = $meta['transfer_to'] ?? 'cuenta destino';
                $this->walletSvc->recordMovement([
                    'company_id' => $record->company_id,
                    'currency' => strtoupper($record->currency ?: 'COP'),
                    'country_code' => strtoupper($record->country_code ?: 'CO'),
                    'amount' => (float) $record->amount,
                    'type' => 'credit',
                    'action_type' => 'transfer_out_reversal',
                    'reference_type' => 'daily_record',
                    'reference_id' => $record->id,
                    'description' => "Reversión transferencia a {$destino}",
                ]);
            }

            // Auditoría: dejar constancia de QUIÉN eliminó antes del soft delete.
            $record->deleted_by = Auth::id();
            $record->save();
            $record->delete();
            return $this->successResponse(['success' => true, 'message' => 'Registro eliminado']);
        });
    }

    /**
     * Edita un movimiento del día por equivocación, con OBSERVACIÓN OBLIGATORIA
     * y trazabilidad (monto anterior→actual, delta = impacto en caja, quién).
     * Fase 1: solo ingreso/gasto (no tocan wallet; la caja se recalcula sola).
     * Reglas: solo el día en curso y con la caja ABIERTA.
     */
    public function update($request, int $id)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $record = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->find($id);
        if (!$record) return $this->errorResponse('Registro no encontrado', 404);

        // Fase 1: solo ingreso/gasto. La transferencia mueve el saldo persistido
        // de la wallet (con estado) → se corregirá en una fase posterior con
        // ajuste de ledger; por ahora se corrige eliminando y recreando.
        if (!in_array($record->type, ['ingreso', 'gasto'], true)) {
            return $this->errorResponse(
                'Por ahora solo se pueden editar movimientos de tipo ingreso o gasto. Las transferencias se corrigen eliminando y recreando.',
                422
            );
        }

        // Observación OBLIGATORIA (motivo del ajuste).
        $observation = trim((string) $request->input('observation', ''));
        if (mb_strlen($observation) < 3) {
            return $this->errorResponse('Debes indicar el motivo del ajuste (mínimo 3 caracteres).', 422);
        }

        // Monto nuevo válido.
        $newAmount = $request->input('amount', $record->amount);
        if (!is_numeric($newAmount) || (float) $newAmount <= 0) {
            return $this->errorResponse('El monto debe ser un número mayor a 0.', 422);
        }
        $newAmount = round((float) $newAmount, 2);

        // Solo el día en curso y con la caja abierta (el corte es definitivo).
        $tz = $this->companyTz($companyId);
        $recordDate = optional($record->recorded_at)->setTimezone($tz)->toDateString();
        $today = Carbon::now($tz)->toDateString();
        if ($recordDate !== $today) {
            return $this->errorResponse('Solo se pueden editar los movimientos del día en curso.', 409);
        }
        if ($recordDate && $this->closureSvc->isDayClosed($companyId, $recordDate)) {
            return $this->errorResponse(
                'No se puede editar: la caja del día ' . $recordDate . ' está cerrada. El corte del día ya es definitivo.',
                409
            );
        }

        // Categoría/descripción: si no vienen en el request, se conservan.
        $newCategory = $request->has('category') ? ($request->input('category') ?: null) : $record->category;
        $newDescription = $request->has('description') ? ($request->input('description') ?: null) : $record->description;

        $old = [
            'amount' => (float) $record->amount,
            'category' => $record->category,
            'description' => $record->description,
        ];

        // Evidencia: REEMPLAZO CON RETENCIÓN. La imagen anterior NUNCA se borra
        // del disco; solo queda referenciada en la auditoría (old_evidence). Si
        // suben fotos nuevas, reemplazan el set actual del movimiento. El file IO
        // va FUERA de la transacción.
        $meta = is_array($record->metadata) ? $record->metadata : [];
        $oldEvidence = $meta['evidence_paths']
            ?? (isset($meta['evidence_path']) ? [$meta['evidence_path']] : []);
        $oldEvidence = is_array($oldEvidence) ? array_values($oldEvidence) : [];
        $newEvidence = $oldEvidence;
        $evidenceChanged = false;
        if ($request->hasFile('evidence')) {
            $files = $request->file('evidence');
            if (!is_array($files)) $files = [$files];
            $stored = [];
            foreach (array_slice($files, 0, 3) as $file) {
                $stored[] = $file->store("collection/daily-records/evidence/{$companyId}", 'public');
            }
            if (!empty($stored)) {
                $newEvidence = $stored;   // reemplaza el set actual del movimiento
                $evidenceChanged = true;  // (las anteriores NO se borran)
            }
        }

        return DB::connection('collection_pgsql')->transaction(function () use ($record, $newAmount, $newCategory, $newDescription, $old, $observation, $request, $meta, $oldEvidence, $newEvidence, $evidenceChanged) {
            $record->amount = $newAmount;
            $record->category = $newCategory;
            $record->description = $newDescription;
            if ($evidenceChanged) {
                $meta['evidence_paths'] = $newEvidence;
                unset($meta['evidence_path']); // normaliza al formato array
                $record->metadata = $meta;
            }
            $record->save();

            \App\Models\Collection\CollectionDailyRecordAudit::create([
                'company_id' => $record->company_id,
                'daily_record_id' => $record->id,
                'user_id' => Auth::id(),
                'action' => 'update',
                'old_amount' => $old['amount'],
                'new_amount' => $newAmount,
                'delta' => round($newAmount - $old['amount'], 2),
                'old_category' => $old['category'],
                'new_category' => $newCategory,
                'old_description' => $old['description'],
                'new_description' => $newDescription,
                'old_evidence' => $oldEvidence,
                'new_evidence' => $newEvidence,
                'observation' => $observation,
                'ip' => $request->ip(),
                'created_at' => Carbon::now(),
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Movimiento actualizado y auditado',
                'data' => $record->fresh(),
            ]);
        });
    }

    /**
     * Historial de ajustes (trazabilidad) de un registro diario.
     */
    public function auditHistory($request, int $id)
    {
        $companyId = $this->resolveCompanyId($request->company_id);

        $audits = \App\Models\Collection\CollectionDailyRecordAudit::where('company_id', $companyId)
            ->where('daily_record_id', $id)
            ->orderByDesc('created_at')
            ->get();

        // Los nombres de usuario viven en MySQL (core), no en collection_pgsql.
        $userIds = $audits->pluck('user_id')->filter()->unique()->all();
        $names = empty($userIds)
            ? collect()
            : \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');

        $data = $audits->map(function ($a) use ($names) {
            return [
                'id' => $a->id,
                'action' => $a->action,
                'old_amount' => (float) $a->old_amount,
                'new_amount' => (float) $a->new_amount,
                'delta' => (float) $a->delta,
                'old_category' => $a->old_category,
                'new_category' => $a->new_category,
                'old_description' => $a->old_description,
                'new_description' => $a->new_description,
                'old_evidence' => $a->old_evidence ?: [],
                'new_evidence' => $a->new_evidence ?: [],
                'observation' => $a->observation,
                'user_id' => $a->user_id,
                'user_name' => $names[$a->user_id] ?? null,
                'created_at' => optional($a->created_at)->toISOString(),
            ];
        });

        return $this->successResponse(['success' => true, 'data' => $data]);
    }

    private function resolveCompanyId($requestedId): int
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        $companyId = $user ? ($user->company->id ?? $user->seller->company_id ?? null) : null;
        // Fail-closed: sin empresa resoluble cortamos con 422 (no null), para
        // que Collection nunca consulte con WHERE company_id IS NULL.
        abort_if($companyId === null, 422, 'No se pudo resolver la empresa para la operación de Collection.');
        return (int) $companyId;
    }
}
