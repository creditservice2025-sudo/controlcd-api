<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionExpense;
use App\Models\Collection\CollectionPayment;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CollectionExpenseService
{
    use ApiResponse;

    private const CONNECTION = 'collection_pgsql';

    public function __construct(
        private readonly CollectionPartitionService $partitionService,
        private readonly CollectionCashClosureService $closureSvc,
    ) {
    }

    public function index($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        // Usar timezone del cliente si viene en el request, sino America/Bogota por defecto
        $tz = $request->query('timezone') ?: 'America/Bogota';
        $date = $request->query('date', Carbon::now($tz)->toDateString());

        // Rango del dia en la zona horaria del cliente, convertido a UTC
        $dayStart = Carbon::parse($date . ' 00:00:00', $tz)->utc();
        $dayEnd = Carbon::parse($date . ' 23:59:59', $tz)->utc();

        // Todos los gastos de la empresa (caja compartida) - sin filtro por usuario
        $query = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$dayStart, $dayEnd]);

        $expenses = $query->orderBy('recorded_at', 'desc')->get();

        // Total de gastos aprobados
        $totalExpenses = $expenses->where('status', 'approved')->sum('amount');

        // Total de cobros del dia (caja compartida)
        $totalCollections = (float) CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('recorded_at', [$dayStart, $dayEnd])
            ->sum('amount_paid');

        return $this->successResponse([
            'expenses' => $expenses,
            'summary' => [
                'total_expenses' => (float) $totalExpenses,
                'total_collections' => (float) $totalCollections,
                'net_balance' => (float) ($totalCollections - $totalExpenses),
            ]
        ]);
    }

    public function create($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        // Bloquear si hoy ya tiene cierre de caja activo.
        $tz = $request->input('timezone', 'America/Bogota');
        $today = Carbon::now($tz)->toDateString();
        if ($this->closureSvc->isDayClosed($companyId, $today)) {
            return $this->errorResponse(
                'No se pueden registrar gastos: la caja del día ' . $today . ' está cerrada. Reabre el cierre primero.',
                409
            );
        }

        $this->partitionService->ensurePartitions($companyId);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'category' => 'required|string|max:50',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'evidence' => 'nullable|image|max:5120', // 5MB max
        ]);

        $metadata = [];
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store("collection/expenses/evidence/{$companyId}", 'public');
            $metadata['evidence_path'] = $path;
        }

        $expense = CollectionExpense::create([
            'company_id' => $companyId,
            'user_id' => Auth::id(),
            'amount' => $validated['amount'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'],
            'status' => 'approved',
            'recorded_at' => Carbon::now(),
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'metadata' => $metadata,
        ]);

        // Sync with Centralized Wallet
        app(\App\Services\Collection\CollectionWalletService::class)->recordMovement([
            'company_id' => $companyId,
            'currency' => $request->currency ?? 'COP',
            'country_code' => $request->country_code ?? 'CO',
            'amount' => $validated['amount'],
            'type' => 'debit', // Expense/Outflow
            'action_type' => 'expense',
            'reference_type' => 'expense',
            'reference_id' => $expense->id,
            'description' => $validated['description'] ?? "Gasto: {$validated['category']}",
        ]);

        return $this->successCreatedResponse([
            'success' => true,
            'message' => 'Gasto registrado correctamente',
            'data' => $expense
        ]);
    }

    /**
     * El controller (ResolvesCollectionCompany trait) ya validó y forzó
     * el company_id correcto en el request. Usamos ese valor si existe;
     * si no (invocación directa), lo derivamos del usuario autenticado.
     */
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
