<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionCashClosure;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionPayment;
use App\Models\Company;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Cierre de caja diario para el modulo Collection (Deuda & Abono).
 *
 * Esperado = cobros + adiciones de capital + ingresos manuales
 *            − gastos − egresos manuales
 * (Las transferencias manuales son neutras para el total de caja: mueven
 * dinero entre cuentas propias sin afectar el flujo neto.)
 *
 * Declarado = efectivo_contado + transferencias_recibidas
 * Diferencia = declarado − esperado (positivo = sobrante, negativo = faltante)
 */
class CollectionCashClosureService
{
    use ApiResponse;

    public function __construct(private readonly CollectionPartitionService $partitionService)
    {
    }

    /**
     * Devuelve el resumen calculado del dia + cierre activo (si existe) +
     * fechas anteriores con movimientos sin cierre (pending_previous_days) +
     * fecha "hoy" segun la zona horaria del cliente (server_today).
     */
    public function getDaySummary(int $companyId, string $date, ?string $tz = null, ?string $countryCode = null)
    {
        // Siempre en zona horaria de la EMPRESA (companies.timezone), para que el
        // "Corte del día" coincida con el listado de movimientos y el auto-cierre.
        $tz = Company::find($companyId)?->timezone ?: 'America/Bogota';
        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->utc();
        $serverToday = Carbon::now($tz)->toDateString();

        $totals = $this->computeTotals($companyId, $date, $dayStart, $dayEnd, $countryCode);
        $closure = $this->findActiveClosure($companyId, $date, $countryCode);
        $pendingPrev = $this->findPendingPreviousDays($companyId, $date, $tz, $countryCode);
        $moduleStart = $this->findModuleStartDate($companyId, $tz);
        $openingBalance = $this->findOpeningBalance($companyId, $date, $countryCode);

        return $this->successResponse([
            'date' => $date,
            'timezone' => $tz,
            'server_today' => $serverToday,
            // Instante EXACTO del servidor, en la zona de la empresa. El
            // formulario propone la hora con esto y no con el reloj del
            // aparato: un teléfono con la hora corrida hacía nacer el
            // movimiento a una hora que nunca ocurrió (y si iba adelantado,
            // en un día de negocio que todavía no empezó).
            'server_now' => Carbon::now($tz)->toIso8601String(),
            'module_start_date' => $moduleStart,
            'country_code' => $countryCode,
            'totals' => $totals,
            'closure' => $closure ? $this->hydrateClosure($closure) : null,
            'pending_previous_days' => $pendingPrev,
            'opening_balance' => $openingBalance,
        ]);
    }

    /**
     * Saldo de apertura para $date = total_declarado del último cierre
     * activo con closure_date < $date. Si no hay cierres anteriores, 0.
     */
    /**
     * Saldo de apertura (arrastre) para una fecha: total declarado del último
     * cierre activo anterior. Público para que otros servicios (ej. el resumen
     * por período con acumulado) siembren el saldo inicial.
     */
    public function getOpeningBalance(int $companyId, string $date, ?string $countryCode = null): float
    {
        return $this->findOpeningBalance($companyId, $date, $countryCode);
    }

    private function findOpeningBalance(int $companyId, string $date, ?string $countryCode): float
    {
        $q = CollectionCashClosure::where('company_id', $companyId)
            ->where('status', CollectionCashClosure::STATUS_CLOSED)
            ->where('closure_date', '<', $date);
        if ($countryCode) {
            $q->where('country_code', strtoupper($countryCode));
        } else {
            $q->whereNull('country_code');
        }
        $previous = $q->orderByDesc('closure_date')->orderByDesc('id')->first();
        return $previous ? (float) $previous->total_declarado : 0.0;
    }

    /**
     * Devuelve la fecha (YYYY-MM-DD, tz cliente) del primer movimiento de la
     * empresa en el modulo Collection. Sirve como cota inferior para navegar
     * hacia atras y como "fecha de inicio" del modulo.
     */
    private function findModuleStartDate(int $companyId, string $tz): ?string
    {
        // Cobros: recorded_at es timestamptz → se convierte a la zona de la
        // empresa. Gastos/registros/adiciones: business_date ya es fecha contable.
        $minPayment = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')->min('recorded_at');
        $minExpense = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->min('business_date');
        $minDr = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')->min('business_date');
        $minAdic = CollectionCapitalAddition::where('company_id', $companyId)
            ->min('business_date');

        $candidates = [];
        if ($minPayment) $candidates[] = Carbon::parse($minPayment)->setTimezone($tz)->toDateString();
        if ($minExpense) $candidates[] = $minExpense instanceof Carbon ? $minExpense->toDateString() : (string) $minExpense;
        if ($minDr) $candidates[] = $minDr instanceof Carbon ? $minDr->toDateString() : (string) $minDr;
        if ($minAdic) {
            $candidates[] = $minAdic instanceof Carbon
                ? $minAdic->toDateString()
                : Carbon::parse($minAdic)->toDateString();
        }
        if (empty($candidates)) return null;
        sort($candidates);
        return $candidates[0];
    }

    // NOTA (limpieza): closeDay() (cierre manual) y reopen() (reapertura) se
    // ELIMINARON. El corte de caja es 100% automático (autoCloseDay vía cron a
    // las 23:59:59 hora de la empresa) y NO hay rutas que expongan cierre ni
    // reapertura manual. Ambos métodos eran código muerto (sin llamadores ni
    // rutas), y sus mensajes ("reabre el cierre primero") eran engañosos.
    // El display histórico de cierres reabiertos se conserva en hydrateClosure
    // (lee reopened_at/reopened_by), por si existieran registros antiguos.

    /**
     * Corte de caja automatico (total) del dia. Lo invoca el cron a las
     * 23:59:59 hora local de la empresa. Ya NO existe cierre manual: el dia
     * queda CERRADO (status=closed) directamente, con los totales calculados.
     * Sin conteo fisico: se declara el efectivo igual al esperado, por lo que
     * no hay faltante/sobrante que reconciliar.
     */
    public function autoCloseDay(int $companyId, string $date, string $tz, ?string $countryCode = null, ?int $systemUserId = null)
    {
        // Si ya hay un cierre activo o auto_pending, no duplicar.
        $existing = CollectionCashClosure::where('company_id', $companyId)
            ->where('closure_date', $date)
            ->whereIn('status', [
                CollectionCashClosure::STATUS_CLOSED,
                CollectionCashClosure::STATUS_AUTO_PENDING,
            ])
            ->first();
        if ($existing) return null;

        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->utc();
        $totals = $this->computeTotals($companyId, $date, $dayStart, $dayEnd, $countryCode);

        $this->partitionService->ensurePartitions($companyId);

        return CollectionCashClosure::create([
            'company_id' => $companyId,
            'closure_date' => $date,
            'country_code' => $countryCode,
            'currency' => null,
            // Si no hay un usuario explicito (cron headless), se usa null.
            'user_id' => $systemUserId ?? 0,
            'total_cobros' => $totals['total_cobros'],
            'total_adiciones' => $totals['total_adiciones'],
            'total_ingresos_manuales' => $totals['total_ingresos_manuales'],
            'total_gastos' => $totals['total_gastos'],
            'total_egresos_manuales' => $totals['total_egresos_manuales'],
            'total_transferencias' => $totals['total_transferencias'],
            'esperado' => $totals['esperado'],
            // Corte total automático: sin conteo físico. Se declara igual al
            // esperado, de modo que no queda faltante/sobrante por reconciliar.
            'efectivo_contado' => $totals['esperado'],
            'transferencias_recibidas' => 0,
            'total_declarado' => $totals['esperado'],
            'faltante_sobrante' => 0,
            'notas' => '[Cierre automático del sistema] Corte del día a las 23:59:59.',
            'status' => CollectionCashClosure::STATUS_CLOSED,
            'closed_at' => Carbon::now(),
        ]);
    }

    /**
     * Red de seguridad: cierra (corte total) los días ANTERIORES a hoy que
     * tienen movimientos y aún no tienen cierre. Cubre el caso de que el cron
     * no corriera a las 23:59 de algún día (servidor caído, deploy), para que
     * ningún día quede abierto indefinidamente al haberse quitado el cierre
     * manual.
     *
     * @return string[] fechas cerradas
     */
    public function autoCloseMissedDays(int $companyId, string $tz, ?string $countryCode = null): array
    {
        $today = Carbon::now($tz)->toDateString();
        $pending = $this->findPendingPreviousDays($companyId, $today, $tz, $countryCode);
        $closed = [];
        foreach ($pending as $date) {
            if ($this->autoCloseDay($companyId, $date, $tz, $countryCode)) {
                $closed[] = $date;
            }
        }
        return $closed;
    }

    /**
     * Valida un cierre auto_pending: el admin captura el efectivo contado y
     * transferencias reales, recalcula la diferencia y promueve a closed.
     */
    public function validateClosure(int $companyId, int $closureId, array $payload)
    {
        $user = Auth::user();
        if (!$user) return $this->errorResponse('No autenticado', 401);
        if (!in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('Solo administradores pueden validar cierres', 403);
        }

        $closure = CollectionCashClosure::where('company_id', $companyId)
            ->where('id', $closureId)
            ->first();
        if (!$closure) return $this->errorResponse('Cierre no encontrado', 404);
        if ($closure->status !== CollectionCashClosure::STATUS_AUTO_PENDING) {
            return $this->errorResponse('Solo cierres con estado auto_pending se pueden validar', 409);
        }

        $efectivo = (float) ($payload['efectivo_contado'] ?? 0);
        $transfer = (float) ($payload['transferencias_recibidas'] ?? 0);
        $totalDeclarado = round($efectivo + $transfer, 2);
        $diferencia = round($totalDeclarado - (float) $closure->esperado, 2);

        $closure->efectivo_contado = $efectivo;
        $closure->transferencias_recibidas = $transfer;
        $closure->total_declarado = $totalDeclarado;
        $closure->faltante_sobrante = $diferencia;
        if (!empty($payload['notas'])) {
            $closure->notas = trim(($closure->notas ?? '') . "\n[Validado] " . $payload['notas']);
        }
        $closure->status = CollectionCashClosure::STATUS_CLOSED;
        $closure->validated_by = $user->id;
        $closure->validated_at = Carbon::now();
        $closure->save();

        return $this->successResponse([
            'success' => true,
            'message' => 'Cierre validado correctamente',
            'data' => $this->hydrateClosure($closure->fresh()),
        ]);
    }

    /**
     * Lista cierres en estado auto_pending (pendientes de validación admin).
     */
    public function listPendingValidation(int $companyId)
    {
        $rows = CollectionCashClosure::where('company_id', $companyId)
            ->where('status', CollectionCashClosure::STATUS_AUTO_PENDING)
            ->orderByDesc('closure_date')->orderByDesc('id')
            ->get();

        $items = $rows->map(fn($c) => $this->hydrateClosure($c));
        return $this->successResponse([
            'items' => $items,
            'count' => $items->count(),
        ]);
    }

    /**
     * Lista cierres de caja paginados (historial).
     */
    public function listClosures(int $companyId, ?string $from = null, ?string $to = null, int $perPage = 30)
    {
        $q = CollectionCashClosure::where('company_id', $companyId)
            ->orderByDesc('closure_date')
            ->orderByDesc('id');

        if ($from) $q->where('closure_date', '>=', $from);
        if ($to) $q->where('closure_date', '<=', $to);

        $rows = $q->paginate($perPage);

        $userIds = collect($rows->items())
            ->flatMap(fn($r) => [$r->user_id, $r->reopened_by])
            ->filter()->unique()->values()->all();
        $users = !empty($userIds)
            ? User::whereIn('id', $userIds)->pluck('name', 'id')->all()
            : [];

        $items = collect($rows->items())->map(fn($c) => $this->hydrateClosure($c, $users));

        return $this->successResponse([
            'items' => $items,
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function computeTotals(int $companyId, string $date, Carbon $dayStart, Carbon $dayEnd, ?string $countryCode): array
    {
        // Cobros y adiciones de capital son del lado de CRÉDITO y NO cuentan en el
        // cierre de caja operativo (Registros Diarios). Se dejan en 0 para que no
        // afecten el efectivo esperado ni el auto-cierre. El crédito se refleja en
        // el Dashboard/wallet. Ver memoria project_collection_registros_operativos.
        $totalCobros = 0.0;
        $totalAdiciones = 0.0;

        // Gastos aprobados del dia (por fecha contable business_date).
        $gastosQ = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->where('business_date', $date);
        $totalGastos = (float) $gastosQ->sum('amount');

        // Registros manuales del dia (por fecha contable business_date).
        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('business_date', $date);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        $drRows = $drQ->get(['type', 'amount']);

        $totalIngresosManuales = (float) $drRows->where('type', 'ingreso')->sum('amount');
        $totalEgresosManuales = (float) $drRows->where('type', 'gasto')->sum('amount');
        $totalTransferencias = (float) $drRows->where('type', 'transferencia')->sum('amount');

        // Nota: las adiciones de capital SALEN de caja (al cliente),
        // por eso restan al efectivo esperado.
        // Las transferencias también restan: salen de la wallet del módulo
        // hacia una cuenta destino externa (action=transfer_out).
        $esperado = round(
            $totalCobros + $totalIngresosManuales
            - $totalAdiciones - $totalGastos - $totalEgresosManuales
            - $totalTransferencias,
            2
        );

        return [
            'total_cobros' => round($totalCobros, 2),
            'total_adiciones' => round($totalAdiciones, 2),
            'total_ingresos_manuales' => round($totalIngresosManuales, 2),
            'total_gastos' => round($totalGastos, 2),
            'total_egresos_manuales' => round($totalEgresosManuales, 2),
            'total_transferencias' => round($totalTransferencias, 2),
            'esperado' => $esperado,
        ];
    }

    /**
     * Devuelve fechas (YYYY-MM-DD) anteriores a $beforeDate que tienen al menos
     * un movimiento (cobros, gastos, daily records o adiciones) y aun NO tienen
     * un cierre activo. Limita la busqueda a [last_closed_date+1 .. beforeDate-1]
     * o, si no hay cierres aun, a los ultimos 30 dias.
     */
    private function findPendingPreviousDays(int $companyId, string $beforeDate, string $tz, ?string $countryCode): array
    {
        // Anchor: dia siguiente al ultimo cierre activo (sea de la fecha que sea, no solo previa).
        $lastClosed = CollectionCashClosure::where('company_id', $companyId)
            ->where('status', CollectionCashClosure::STATUS_CLOSED)
            ->where('closure_date', '<', $beforeDate)
            ->orderByDesc('closure_date')->orderByDesc('id')
            ->first();

        $startDate = $lastClosed
            ? Carbon::parse($lastClosed->closure_date)->addDay()->toDateString()
            : Carbon::parse($beforeDate, $tz)->subDays(30)->toDateString();

        $endDate = Carbon::parse($beforeDate)->subDay()->toDateString();
        if ($startDate > $endDate) return [];

        $startUtc = Carbon::parse($startDate . ' 00:00:00', $tz)->utc();
        $endUtc = Carbon::parse($endDate . ' 23:59:59', $tz)->utc();

        // Recolectar fechas con movimientos en el rango
        $dates = collect();

        $payments = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$startUtc, $endUtc])
            ->selectRaw("DISTINCT to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d", [$tz])
            ->pluck('d');
        $dates = $dates->merge($payments);

        $expenses = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->where('status', 'approved')
            ->whereBetween('business_date', [$startDate, $endDate])
            ->selectRaw("DISTINCT to_char(business_date, 'YYYY-MM-DD') as d")
            ->pluck('d');
        $dates = $dates->merge($expenses);

        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('business_date', [$startDate, $endDate]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        $drs = $drQ->selectRaw("DISTINCT to_char(business_date, 'YYYY-MM-DD') as d")
            ->pluck('d');
        $dates = $dates->merge($drs);

        $adic = CollectionCapitalAddition::where('company_id', $companyId)
            ->whereBetween('business_date', [$startDate, $endDate])
            ->distinct()->pluck('business_date')
            ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d);
        $dates = $dates->merge($adic);

        $datesWithMovements = $dates->filter()->unique()->values();
        if ($datesWithMovements->isEmpty()) return [];

        // Restar fechas que ya tienen cierre activo
        $closedDates = CollectionCashClosure::where('company_id', $companyId)
            ->where('status', CollectionCashClosure::STATUS_CLOSED)
            ->whereBetween('closure_date', [$startDate, $endDate])
            ->pluck('closure_date')
            ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d);

        return $datesWithMovements
            ->reject(fn($d) => $closedDates->contains($d))
            ->sort()->values()->all();
    }

    /**
     * Determina si una fecha tiene un cierre de caja activo (no reabierto).
     * Usado por otros servicios para bloquear creacion/edicion/eliminacion
     * de movimientos en dias cerrados.
     */
    public function isDayClosed(int $companyId, string $date, ?string $countryCode = null): bool
    {
        return $this->findActiveClosure($companyId, $date, $countryCode) !== null;
    }

    private function findActiveClosure(int $companyId, string $date, ?string $countryCode): ?CollectionCashClosure
    {
        $q = CollectionCashClosure::where('company_id', $companyId)
            ->where('closure_date', $date)
            ->where('status', CollectionCashClosure::STATUS_CLOSED);
        if ($countryCode) {
            $q->where('country_code', strtoupper($countryCode));
        } else {
            $q->whereNull('country_code');
        }
        return $q->orderByDesc('id')->first();
    }

    private function hydrateClosure(CollectionCashClosure $c, array $users = []): array
    {
        if (empty($users)) {
            $ids = array_filter([$c->user_id, $c->reopened_by]);
            $users = !empty($ids) ? User::whereIn('id', $ids)->pluck('name', 'id')->all() : [];
        }

        return [
            'id' => $c->id,
            'company_id' => $c->company_id,
            'closure_date' => optional($c->closure_date)->toDateString(),
            'country_code' => $c->country_code,
            'currency' => $c->currency,
            'user_id' => $c->user_id,
            'user_name' => $users[$c->user_id] ?? null,
            'total_cobros' => (float) $c->total_cobros,
            'total_adiciones' => (float) $c->total_adiciones,
            'total_ingresos_manuales' => (float) $c->total_ingresos_manuales,
            'total_gastos' => (float) $c->total_gastos,
            'total_egresos_manuales' => (float) $c->total_egresos_manuales,
            'total_transferencias' => (float) $c->total_transferencias,
            'esperado' => (float) $c->esperado,
            'efectivo_contado' => (float) $c->efectivo_contado,
            'transferencias_recibidas' => (float) $c->transferencias_recibidas,
            'total_declarado' => (float) $c->total_declarado,
            'faltante_sobrante' => (float) $c->faltante_sobrante,
            'notas' => $c->notas,
            'status' => $c->status,
            'closed_at' => optional($c->closed_at)->toISOString(),
            'reopened_at' => optional($c->reopened_at)->toISOString(),
            'reopened_by' => $c->reopened_by,
            'reopened_by_name' => $c->reopened_by ? ($users[$c->reopened_by] ?? null) : null,
        ];
    }
}
