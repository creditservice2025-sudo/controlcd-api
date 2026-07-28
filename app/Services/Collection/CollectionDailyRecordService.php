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
     * ¿La caja dada es la caja principal (default) de la empresa? Se usa para
     * decidir dónde mostrar filas que no pertenecen a una caja concreta
     * (ej. adiciones de capital del flujo de créditos).
     */
    private function isDefaultCashbox(int $companyId, int $cashboxId): bool
    {
        return \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)
            ->where('id', $cashboxId)
            ->where('is_default', true)
            ->exists();
    }

    /**
     * Saldo de apertura (arrastre) de una caja ANTES de una fecha: saldo inicial
     * de la caja (si fue creada antes de `from`) + neto de sus movimientos con
     * día contable anterior a `from`. Es la semilla del "saldo acumulado" del
     * resumen por período cuando se ve una caja secundaria.
     */
    private function cashboxOpeningSeed(int $companyId, int $cashboxId, string $from, string $tz): float
    {
        $cb = \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)->find($cashboxId);
        if (!$cb) return 0.0;

        $seed = 0.0;
        // El saldo inicial cuenta como arrastre solo si la caja se creó antes del rango.
        if ((float) $cb->opening_balance != 0.0 && $cb->created_at) {
            $cbTz = TimezoneHelper::timezoneForCountryCode($cb->country_code) ?: $tz;
            $ap = Carbon::parse($cb->created_at)->setTimezone($cbTz)->toDateString();
            if ($ap < $from) $seed += (float) $cb->opening_balance;
        }

        // Neto de los movimientos de la caja anteriores al rango.
        $net = CollectionDailyRecord::where('company_id', $companyId)
            ->where('cashbox_id', $cashboxId)
            ->whereNull('deleted_at')
            ->where('business_date', '<', $from)
            ->selectRaw("SUM(CASE WHEN type = 'ingreso' THEN amount WHEN type IN ('gasto','transferencia') THEN -amount ELSE 0 END) as net")
            ->value('net');

        return round($seed + (float) $net, 2);
    }

    /**
     * Resuelve la caja a la que pertenece un movimiento. Si el id enviado no
     * es válido para la empresa (o no se envía), cae a la caja principal
     * (default). Devuelve null si la empresa no tiene ninguna caja.
     */
    private function resolveCashboxId(int $companyId, $requested): ?int
    {
        $requested = ($requested !== null && $requested !== '') ? (int) $requested : null;

        if ($requested) {
            $valid = \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)
                ->where('id', $requested)
                ->where('active', true)
                ->value('id');
            if ($valid) return (int) $valid;
        }

        $default = \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        return $default ? (int) $default : null;
    }

    /**
     * Tendencia de movimientos día por día en un rango. Devuelve por cada
     * día: cobros, ingresos manuales, gastos aprobados, egresos manuales,
     * transferencias salientes, adiciones de capital y balance neto.
     *
     * Usado por el reporte Excel "tendencia mensual".
     */
    public function getTrend(int $companyId, string $from, string $to, string $tz, ?string $countryCode = null, ?int $cashboxId = null)
    {
        $tz = $this->companyTz($companyId);
        $bucket = $this->dailyBuckets($companyId, $from, $to, $tz, $countryCode, $cashboxId);

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
    private function dailyBuckets(int $companyId, string $from, string $to, string $tz, ?string $countryCode, ?int $cashboxId = null): array
    {
        $startUtc = Carbon::parse($from . ' 00:00:00', $tz)->utc();
        $endUtc = Carbon::parse($to . ' 23:59:59', $tz)->utc();

        // Al ver una caja concreta, los cobros de créditos, gastos aprobados y
        // adiciones de capital (que son company-wide, del lado de préstamos) solo
        // se incluyen si es la caja principal. Una caja secundaria solo agrega su
        // propia bitácora (ingreso/gasto/transferencia).
        $includeCompanyWide = !$cashboxId || $this->isDefaultCashbox($companyId, $cashboxId);

        // 1) Registros manuales por día contable y tipo.
        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        if ($cashboxId) $drQ->where('cashbox_id', $cashboxId);
        $drRows = $drQ
            ->selectRaw("to_char(business_date, 'YYYY-MM-DD') as d, type, SUM(amount) as total")
            ->groupBy('d', 'type')
            ->get();

        // Cobros (payments) y adiciones de capital son del lado de CRÉDITO y NO
        // se reflejan en Registros Diarios (sección solo operativa). Se dejan como
        // colecciones vacías para que el corte no los cuente. El crédito se ve en
        // el Dashboard/wallet, que no se toca. Ver memoria
        // project_collection_registros_operativos.
        $payRows = collect();
        $expRows = collect();
        $adicRows = collect();
        if ($includeCompanyWide) {
        // Gastos aprobados (expenses) por día contable — operativo, se incluye.
        $expRows = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->where('status', 'approved')
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw("to_char(business_date, 'YYYY-MM-DD') as d, SUM(amount) as total")
            ->groupBy('d')
            ->get()
            ->keyBy('d');
        } // fin includeCompanyWide

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

        // Apertura de la caja: si su fecha de creación cae dentro del rango, se
        // suma como ingreso en ese día (igual que en la vista diaria). Si es
        // anterior al rango, no entra aquí: va en el saldo de apertura del
        // resumen por período (ver buildPeriodSummary).
        if ($cashboxId) {
            $cb = \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)->find($cashboxId);
            if ($cb && (float) $cb->opening_balance != 0.0 && $cb->created_at) {
                $cbTz = TimezoneHelper::timezoneForCountryCode($cb->country_code) ?: $tz;
                $ap = Carbon::parse($cb->created_at)->setTimezone($cbTz)->toDateString();
                if ($ap >= $from && $ap <= $to) {
                    $ensure($ap);
                    $bucket[$ap]['ingresos'] += (float) $cb->opening_balance;
                }
            }
        }

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
    public function periodSummary(int $companyId, string $granularity, string $from, string $to, ?string $countryCode = null, ?int $cashboxId = null)
    {
        return $this->successResponse(
            $this->buildPeriodSummary($companyId, $granularity, $from, $to, $countryCode, $cashboxId)
        );
    }

    /**
     * Construye el resumen por período (misma data que expone periodSummary).
     * Separado para reutilizarlo en el export PDF sin re-consultar la API.
     */
    public function buildPeriodSummary(int $companyId, string $granularity, string $from, string $to, ?string $countryCode = null, ?int $cashboxId = null): array
    {
        $tz = $this->companyTz($companyId);
        $allowed = ['daily', 'weekly', 'biweekly', 'monthly', 'yearly'];
        $granularity = in_array($granularity, $allowed, true) ? $granularity : 'daily';

        $daily = $this->dailyBuckets($companyId, $from, $to, $tz, $countryCode, $cashboxId);

        // Saldo de apertura (arrastre) antes del rango. Para una caja secundaria
        // es su propio arrastre (saldo inicial + neto previo de esa caja); para
        // la vista general / caja principal, el arrastre company-wide del cierre.
        if ($cashboxId && !$this->isDefaultCashbox($companyId, $cashboxId)) {
            $opening = $this->cashboxOpeningSeed($companyId, $cashboxId, $from, $tz);
        } else {
            $opening = $this->closureSvc->getOpeningBalance($companyId, $from, $countryCode);
        }

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
    public function periodComparison(int $companyId, string $granularity, ?string $countryCode = null, ?int $cashboxId = null)
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

        $curr = $this->rangeTotals($companyId, $currFrom, $currTo, $tz, $countryCode, $cashboxId);
        $prev = $this->rangeTotals($companyId, $prevFrom, $prevTo, $tz, $countryCode, $cashboxId);

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
    private function rangeTotals(int $companyId, string $from, string $to, string $tz, ?string $countryCode, ?int $cashboxId = null): array
    {
        $buckets = $this->dailyBuckets($companyId, $from, $to, $tz, $countryCode, $cashboxId);
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
        $cashboxId = $request->query('cashbox_id');
        $cashboxId = ($cashboxId !== null && $cashboxId !== '') ? (int) $cashboxId : null;

        $query = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $query->where('country_code', strtoupper($countryCode));
        if ($cashboxId) $query->where('cashbox_id', $cashboxId);
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

        // Nombre de la caja de cada resultado (el buscador puede abarcar varias).
        $cbIds = $records->pluck('cashbox_id')->filter()->unique()->values()->all();
        if (!empty($cbIds)) {
            $cbNames = \App\Models\Collection\CollectionCashbox::whereIn('id', $cbIds)
                ->pluck('name', 'id');
            $records->each(function ($r) use ($cbNames) {
                $r->cashbox_name = $r->cashbox_id ? ($cbNames[$r->cashbox_id] ?? null) : null;
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
    public function expensesByCategory(int $companyId, string $from, string $to, ?string $countryCode = null, ?int $cashboxId = null)
    {
        $map = $this->spentByCategoryMap($companyId, $from, $to, $countryCode, $cashboxId);
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
    private function spentByCategoryMap(int $companyId, string $from, string $to, ?string $countryCode, ?int $cashboxId = null): array
    {
        $expLabels = [
            'fuel' => 'Combustible', 'food' => 'Alimentación', 'toll' => 'Peaje',
            'maintenance' => 'Mantenimiento', 'other' => 'Otro',
        ];
        // Los gastos aprobados (expenses) son company-wide: solo se incluyen en la
        // vista general / caja principal, no en una caja secundaria.
        $includeCompanyWide = !$cashboxId || $this->isDefaultCashbox($companyId, $cashboxId);

        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('type', 'gasto')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        if ($cashboxId) $drQ->where('cashbox_id', $cashboxId);
        $drRows = $drQ
            ->selectRaw("COALESCE(NULLIF(category, ''), 'Sin categoría') as cat, SUM(amount) as total, COUNT(*) as cnt")
            ->groupBy('cat')->get();

        $expRows = collect();
        if ($includeCompanyWide) {
            $expRows = CollectionExpense::where('company_id', $companyId)
                ->whereNull('deleted_at')->where('status', 'approved')
                ->whereBetween('business_date', [$from, $to])
                ->selectRaw("COALESCE(NULLIF(category, ''), 'Sin categoría') as cat, SUM(amount) as total, COUNT(*) as cnt")
                ->groupBy('cat')->get();
        }

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
    public function downloadPeriodSummaryPdf(int $companyId, string $granularity, string $from, string $to, ?string $countryCode, ?string $currency, ?int $cashboxId = null)
    {
        $data = $this->buildPeriodSummary($companyId, $granularity, $from, $to, $countryCode, $cashboxId);
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
        if ($cashboxId) $movsQ->where('cashbox_id', $cashboxId);
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
        $cashboxId = $request->query('cashbox_id');
        $cashboxId = ($cashboxId !== null && $cashboxId !== '') ? (int) $cashboxId : null;

        $q = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$from, $to]);
        if ($countryCode) $q->where('country_code', strtoupper($countryCode));
        if ($cashboxId) $q->where('cashbox_id', $cashboxId);
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
        // Multi-caja: si se pide una caja específica, se acota la bitácora a ella.
        $cashboxId = $request->query('cashbox_id');
        $cashboxId = ($cashboxId !== null && $cashboxId !== '') ? (int) $cashboxId : null;

        // Si el tipo filtrado es 'capital_addition', no cargamos daily records.
        $dailyRecords = collect();
        if ($type !== 'capital_addition') {
            $q = CollectionDailyRecord::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->where('business_date', $date);

            if ($countryCode) $q->where('country_code', strtoupper($countryCode));
            if ($type && in_array($type, CollectionDailyRecord::TYPES)) $q->where('type', $type);
            if ($cashboxId) $q->where('cashbox_id', $cashboxId);

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

        // Adiciones de capital: pertenecen al flujo de CRÉDITO y ya NO se muestran
        // en Registros Diarios (sección solo operativa). Se dejan vacías. Se ven en
        // el Dashboard/wallet. Ver memoria project_collection_registros_operativos.
        // (buildVirtualCapitalAdditions se conserva por compatibilidad, sin usarse aquí.)
        $capitalVirtual = collect();

        // Apertura de caja: al ver una caja concreta, si su fecha de creación
        // (día contable en su zona) cae en el día visto, se muestra el saldo
        // inicial como un movimiento virtual "apertura" que suma al balance del
        // día (igual que el saldo inicial de un extracto bancario). Solo el día
        // de creación; en días posteriores la apertura ya no aparece.
        $aperturaVirtual = collect();
        $aperturaAmount = 0.0;
        if ($cashboxId && !$type) {
            $cb = \App\Models\Collection\CollectionCashbox::where('company_id', $companyId)
                ->find($cashboxId);
            if ($cb && (float) $cb->opening_balance != 0.0 && $cb->created_at) {
                $cbTz = TimezoneHelper::timezoneForCountryCode($cb->country_code) ?: $tz;
                $aperturaDate = Carbon::parse($cb->created_at)->setTimezone($cbTz)->toDateString();
                if ($aperturaDate === $date) {
                    $aperturaAmount = (float) $cb->opening_balance;
                    $aperturaVirtual->push([
                        'id' => 'apertura-' . $cb->id,
                        'company_id' => $companyId,
                        'cashbox_id' => $cb->id,
                        'user_id' => null,
                        'type' => 'apertura',
                        'category' => 'Apertura de caja',
                        'amount' => $aperturaAmount,
                        'currency' => $cb->currency,
                        'country_code' => $cb->country_code,
                        'description' => 'Saldo inicial de la caja',
                        'recorded_at' => Carbon::parse($cb->created_at)->utc()->toIso8601String(),
                        'business_date' => $aperturaDate,
                        'metadata' => ['virtual' => true],
                        'created_by_name' => null,
                    ]);
                }
            }
        }

        // Combinar y ordenar por timestamp descendente.
        $all = $dailyRecords->concat($capitalVirtual)->concat($aperturaVirtual)->sortByDesc(function ($r) {
            return is_object($r) && isset($r->recorded_at)
                ? Carbon::parse($r->recorded_at)->timestamp
                : (is_array($r) ? strtotime($r['recorded_at'] ?? 'now') : 0);
        })->values();

        $totals = [
            'ingreso' => (float) $dailyRecords->where('type', 'ingreso')->sum('amount'),
            'gasto' => (float) $dailyRecords->where('type', 'gasto')->sum('amount'),
            'transferencia' => (float) $dailyRecords->where('type', 'transferencia')->sum('amount'),
            'capital_addition' => (float) $capitalVirtual->sum(fn($r) => (float) ($r['amount'] ?? 0)),
            'apertura' => $aperturaAmount,
        ];
        // Balance del día = apertura + ingresos − (gastos + transferencias
        // salientes + adiciones de capital). Las transferencias salen de la
        // wallet (action=transfer_out) y las adiciones también son salidas hacia
        // el cliente, por eso restan al balance del día.
        $totals['net'] = $totals['apertura']
            + $totals['ingreso']
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
            if ($cashboxId) $dq->where('cashbox_id', $cashboxId);
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
            // Multi-caja: a qué caja pertenece el movimiento (fallback: la default).
            'cashbox_id' => 'nullable|integer',
        ]);

        // Resolver la caja destino del movimiento. Si no se envía, o no es
        // válida para la empresa, cae a la caja principal (default).
        $cashboxId = $this->resolveCashboxId($companyId, $validated['cashbox_id'] ?? null);
        if (!$cashboxId) {
            return $this->errorResponse('La empresa no tiene una caja configurada.', 422);
        }

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

        return DB::connection('collection_pgsql')->transaction(function () use ($validated, $companyId, $metadata, $recordedAt, $businessDate, $countryCode, $cashboxId) {
            $currency = strtoupper($validated['currency']);

            $record = CollectionDailyRecord::create([
                'company_id' => $companyId,
                'cashbox_id' => $cashboxId,
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

        // Editables: ingreso, gasto y transferencia. Para transferencia, al
        // cambiar el monto se ajusta la wallet por el delta (ver más abajo).
        if (!in_array($record->type, ['ingreso', 'gasto', 'transferencia'], true)) {
            return $this->errorResponse('Este tipo de movimiento no se puede editar.', 422);
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
        // El día contable REAL del movimiento es su business_date (anclado a la
        // zona del PAÍS). Comparar recorded_at reinterpretado en la zona de la
        // EMPRESA fallaba cuando país≠empresa (el movimiento caía en otro día).
        $tz = $this->companyTz($companyId);
        $rtz = TimezoneHelper::timezoneForCountryCode($record->country_code) ?: $tz;
        $recordDate = $record->business_date
            ? Carbon::parse($record->business_date)->toDateString()
            : optional($record->recorded_at)->setTimezone($rtz)->toDateString();
        $today = Carbon::now($rtz)->toDateString();
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

        // Fecha/hora (recorded_at): opcional. La fecha NO puede moverse a otro
        // día (rompería una caja cerrada): el nuevo business_date debe ser HOY y
        // con la caja abierta. Solo se corrige la hora del día en curso.
        $oldRecordedAt = optional($record->recorded_at)->toISOString();
        $newRecordedAtUtc = null;
        $newBusinessDate = null;
        $recordedAtChanged = false;
        if ($request->filled('recorded_at')) {
            $rtz = TimezoneHelper::timezoneForCountryCode($record->country_code) ?: $tz;
            $parsed = Carbon::parse($request->input('recorded_at'), $rtz);
            $nbDate = $parsed->copy()->toDateString();
            if ($nbDate !== Carbon::now($rtz)->toDateString()) {
                return $this->errorResponse('No podés mover el movimiento a otro día; solo se corrige la hora del día en curso.', 409);
            }
            if ($this->closureSvc->isDayClosed($companyId, $nbDate)) {
                return $this->errorResponse('La caja del día ' . $nbDate . ' está cerrada. El corte del día ya es definitivo.', 409);
            }
            $newRecordedAtUtc = $parsed->copy()->utc();
            $newBusinessDate = $nbDate;
            $recordedAtChanged = $newRecordedAtUtc->toISOString() !== $oldRecordedAt;
        }

        // Destino (transfer_to): opcional, solo para transferencia.
        $oldTransferTo = $meta['transfer_to'] ?? null;
        $newTransferTo = $oldTransferTo;
        $transferToChanged = false;
        if ($record->type === 'transferencia' && $request->has('transfer_to')) {
            $newTransferTo = $request->input('transfer_to') ?: null;
            $transferToChanged = ($newTransferTo !== $oldTransferTo);
        }

        return DB::connection('collection_pgsql')->transaction(function () use ($record, $newAmount, $newCategory, $newDescription, $old, $observation, $request, $meta, $oldEvidence, $newEvidence, $evidenceChanged, $newRecordedAtUtc, $newBusinessDate, $recordedAtChanged, $oldRecordedAt, $oldTransferTo, $newTransferTo, $transferToChanged) {
            $record->amount = $newAmount;
            $record->category = $newCategory;
            $record->description = $newDescription;
            if ($recordedAtChanged && $newRecordedAtUtc) {
                $record->recorded_at = $newRecordedAtUtc;
                $record->business_date = $newBusinessDate;
            }
            // Metadata (evidencia + destino): una sola asignación.
            $metaDirty = false;
            if ($evidenceChanged) {
                $meta['evidence_paths'] = $newEvidence;
                unset($meta['evidence_path']); // normaliza al formato array
                $metaDirty = true;
            }
            if ($transferToChanged) {
                $meta['transfer_to'] = $newTransferTo;
                $metaDirty = true;
            }
            if ($metaDirty) $record->metadata = $meta;
            $record->save();

            // Transferencia: la wallet tiene saldo persistido, así que al cambiar
            // el monto hay que ajustarla por el delta (append-only en el ledger).
            //   delta > 0  → salió MÁS de la wallet  → débito adicional
            //   delta < 0  → salió MENOS             → reintegro (crédito)
            if ($record->type === 'transferencia') {
                $delta = round($newAmount - $old['amount'], 2);
                if (abs($delta) >= 0.01) {
                    $this->walletSvc->recordMovement([
                        'company_id' => $record->company_id,
                        'currency' => strtoupper($record->currency ?: 'COP'),
                        'country_code' => strtoupper($record->country_code ?: 'CO'),
                        'amount' => abs($delta),
                        'type' => $delta > 0 ? 'debit' : 'credit',
                        'action_type' => 'transfer_out_adjustment',
                        'reference_type' => 'daily_record',
                        'reference_id' => $record->id,
                        'description' => 'Ajuste por edición de transferencia: '
                            . number_format($old['amount'], 2) . ' → ' . number_format($newAmount, 2),
                    ]);
                }
            }

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
                'extra' => $this->buildAuditExtra($recordedAtChanged, $oldRecordedAt, $newRecordedAtUtc, $transferToChanged, $oldTransferTo, $newTransferTo),
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
                'extra' => $a->extra ?: null,
                'observation' => $a->observation,
                'user_id' => $a->user_id,
                'user_name' => $names[$a->user_id] ?? null,
                'created_at' => optional($a->created_at)->toISOString(),
            ];
        });

        return $this->successResponse(['success' => true, 'data' => $data]);
    }

    // Arma el campo `extra` de la auditoría con los cambios de fecha/hora y
    // destino (solo los que efectivamente cambiaron). Null si no hubo ninguno.
    private function buildAuditExtra(
        bool $recordedAtChanged,
        ?string $oldRecordedAt,
        $newRecordedAtUtc,
        bool $transferToChanged,
        $oldTransferTo,
        $newTransferTo
    ): ?array {
        $extra = [];
        if ($recordedAtChanged) {
            $extra['recorded_at'] = ['old' => $oldRecordedAt, 'new' => optional($newRecordedAtUtc)->toISOString()];
        }
        if ($transferToChanged) {
            $extra['transfer_to'] = ['old' => $oldTransferTo, 'new' => $newTransferTo];
        }
        return !empty($extra) ? $extra : null;
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
