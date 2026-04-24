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
     * Devuelve el resumen calculado del dia + cierre activo (si existe).
     */
    public function getDaySummary(int $companyId, string $date, ?string $tz = null, ?string $countryCode = null)
    {
        $tz = $tz ?: 'America/Bogota';
        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->utc();

        $totals = $this->computeTotals($companyId, $date, $dayStart, $dayEnd, $countryCode);
        $closure = $this->findActiveClosure($companyId, $date, $countryCode);

        return $this->successResponse([
            'date' => $date,
            'timezone' => $tz,
            'country_code' => $countryCode,
            'totals' => $totals,
            'closure' => $closure ? $this->hydrateClosure($closure) : null,
        ]);
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
     * Reabre un cierre activo (solo admin). Marca el registro como reopened
     * para auditoria y libera la fecha para un nuevo cierre.
     */
    public function reopen(int $companyId, int $closureId, ?string $reason = null)
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
        $esperado = round(
            $totalCobros + $totalIngresosManuales
            - $totalAdiciones - $totalGastos - $totalEgresosManuales,
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
