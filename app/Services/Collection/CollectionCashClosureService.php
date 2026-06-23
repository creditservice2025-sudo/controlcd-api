<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCapitalAddition;
use App\Models\Collection\CollectionCashClosure;
use App\Models\Collection\CollectionDailyRecord;
use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionPayment;
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
        $tz = $tz ?: 'America/Bogota';
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
        $minPayment = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')->min('recorded_at');
        $minExpense = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->min('recorded_at');
        $minDr = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')->min('recorded_at');
        $minAdic = CollectionCapitalAddition::where('company_id', $companyId)
            ->min('business_date');

        $candidates = [];
        if ($minPayment) $candidates[] = Carbon::parse($minPayment)->setTimezone($tz)->toDateString();
        if ($minExpense) $candidates[] = Carbon::parse($minExpense)->setTimezone($tz)->toDateString();
        if ($minDr) $candidates[] = Carbon::parse($minDr)->setTimezone($tz)->toDateString();
        if ($minAdic) {
            $candidates[] = $minAdic instanceof Carbon
                ? $minAdic->toDateString()
                : Carbon::parse($minAdic)->toDateString();
        }
        if (empty($candidates)) return null;
        sort($candidates);
        return $candidates[0];
    }

    /**
     * Cierra la caja del dia. Requiere que no exista un cierre activo para
     * esa fecha. Guarda snapshot de los totales calculados y las diferencias
     * contra lo declarado.
     */
    public function closeDay(int $companyId, array $payload)
    {
        $user = Auth::user();
        if (!$user) return $this->errorResponse('No autenticado', 401);

        $date = $payload['closure_date'] ?? Carbon::now()->toDateString();
        $tz = $payload['timezone'] ?? 'America/Bogota';
        $countryCode = $payload['country_code'] ?? null;

        // No permitir doble cierre activo para la misma fecha/empresa/pais.
        $existing = $this->findActiveClosure($companyId, $date, $countryCode);
        if ($existing) {
            return $this->errorResponse(
                'El día ' . $date . ' ya tiene un cierre de caja activo.',
                409
            );
        }

        // No permitir cerrar si hay dias anteriores con movimientos sin cierre.
        $pending = $this->findPendingPreviousDays($companyId, $date, $tz, $countryCode);
        if (!empty($pending)) {
            return response()->json([
                'message' => 'No puedes cerrar este día. Tienes días anteriores sin cerrar: ' . implode(', ', $pending),
                'pending_previous_days' => $pending,
            ], 409);
        }

        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->utc();
        $totals = $this->computeTotals($companyId, $date, $dayStart, $dayEnd, $countryCode);

        $efectivo = (float) ($payload['efectivo_contado'] ?? 0);
        $transfer = (float) ($payload['transferencias_recibidas'] ?? 0);
        $totalDeclarado = round($efectivo + $transfer, 2);
        $diferencia = round($totalDeclarado - (float) $totals['esperado'], 2);

        // Asegurar particion para esta empresa antes de insertar.
        $this->partitionService->ensurePartitions($companyId);

        $closure = CollectionCashClosure::create([
            'company_id' => $companyId,
            'closure_date' => $date,
            'country_code' => $countryCode,
            'currency' => $payload['currency'] ?? null,
            'user_id' => $user->id,
            'total_cobros' => $totals['total_cobros'],
            'total_adiciones' => $totals['total_adiciones'],
            'total_ingresos_manuales' => $totals['total_ingresos_manuales'],
            'total_gastos' => $totals['total_gastos'],
            'total_egresos_manuales' => $totals['total_egresos_manuales'],
            'total_transferencias' => $totals['total_transferencias'],
            'esperado' => $totals['esperado'],
            'efectivo_contado' => $efectivo,
            'transferencias_recibidas' => $transfer,
            'total_declarado' => $totalDeclarado,
            'faltante_sobrante' => $diferencia,
            'notas' => $payload['notas'] ?? null,
            'status' => CollectionCashClosure::STATUS_CLOSED,
            'closed_at' => Carbon::now(),
        ]);

        return $this->successCreatedResponse([
            'success' => true,
            'message' => 'Cierre de caja registrado correctamente',
            'data' => $this->hydrateClosure($closure->fresh()),
        ]);
    }

    /**
     * Reabre un cierre activo (solo admin) — y SOLO si fue del dia actual.
     * Cierres de dias anteriores quedan inmutables para preservar auditoria.
     */
    public function reopen(int $companyId, int $closureId, ?string $reason = null, ?string $tz = null)
    {
        $user = Auth::user();
        if (!$user) return $this->errorResponse('No autenticado', 401);

        $closure = CollectionCashClosure::where('company_id', $companyId)
            ->where('id', $closureId)
            ->first();
        if (!$closure) return $this->errorResponse('Cierre no encontrado', 404);
        if ($closure->status !== CollectionCashClosure::STATUS_CLOSED) {
            return $this->errorResponse('El cierre ya está reabierto', 409);
        }

        // Solo se permite reabrir cierres del dia actual (en la zona horaria del cliente).
        $tz = $tz ?: 'America/Bogota';
        $today = Carbon::now($tz)->toDateString();
        $closureDate = optional($closure->closure_date)->toDateString();
        if ($closureDate !== $today) {
            return $this->errorResponse(
                'Solo se pueden reabrir cierres del día actual. El cierre del ' . $closureDate . ' es inmutable.',
                403
            );
        }

        $closure->status = CollectionCashClosure::STATUS_REOPENED;
        $closure->reopened_at = Carbon::now();
        $closure->reopened_by = $user->id;
        if ($reason) {
            $closure->notas = trim(($closure->notas ?? '') . "\n[Reabierto] " . $reason);
        }
        $closure->save();

        return $this->successResponse([
            'success' => true,
            'message' => 'Cierre reabierto',
            'data' => $this->hydrateClosure($closure->fresh()),
        ]);
    }

    /**
     * Auto-cierre del dia (sin conteo fisico). Lo invoca el cron cuando
     * cierra el dia y el admin no cerro manualmente. Crea un registro con
     * status=auto_pending y SOLO el esperado calculado; deja los campos
     * de conteo fisico en null para que el admin los capture al validar.
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
            'efectivo_contado' => 0,
            'transferencias_recibidas' => 0,
            'total_declarado' => 0,
            'faltante_sobrante' => 0,
            'notas' => '[Auto-cierre del sistema] Pendiente de validación por administrador.',
            'status' => CollectionCashClosure::STATUS_AUTO_PENDING,
            'closed_at' => Carbon::now(),
        ]);
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
        // Cobros del dia
        $cobrosQ = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$dayStart, $dayEnd]);
        if ($countryCode) {
            $cobrosQ->whereIn('credit_id', function ($sub) use ($companyId, $countryCode) {
                $sub->select('id')->from('collection_credits')
                    ->where('company_id', $companyId)
                    ->where('country_code', strtoupper($countryCode));
            });
        }
        $totalCobros = (float) $cobrosQ->sum('amount_paid');

        // Adiciones de capital (salen de caja)
        $adicQ = CollectionCapitalAddition::where('company_id', $companyId)
            ->where('business_date', $date);
        if ($countryCode) {
            $adicQ->whereIn('credit_id', function ($sub) use ($companyId, $countryCode) {
                $sub->select('id')->from('collection_credits')
                    ->where('company_id', $companyId)
                    ->where('country_code', strtoupper($countryCode));
            });
        }
        $totalAdiciones = (float) $adicQ->sum('amount');

        // Gastos aprobados del dia
        $gastosQ = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->whereBetween('recorded_at', [$dayStart, $dayEnd]);
        $totalGastos = (float) $gastosQ->sum('amount');

        // Registros manuales del dia
        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$dayStart, $dayEnd]);
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
            ->whereBetween('recorded_at', [$startUtc, $endUtc])
            ->selectRaw("DISTINCT to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d", [$tz])
            ->pluck('d');
        $dates = $dates->merge($expenses);

        $drQ = CollectionDailyRecord::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$startUtc, $endUtc]);
        if ($countryCode) $drQ->where('country_code', strtoupper($countryCode));
        $drs = $drQ->selectRaw("DISTINCT to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM-DD') as d", [$tz])
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
