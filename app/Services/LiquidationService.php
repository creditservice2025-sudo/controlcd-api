<?php

namespace App\Services;

use App\Models\Liquidation;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LiquidationService
{
    
    use ApiResponse;
    /**
     * Crea una nueva liquidación con validación y cálculos automáticos.
     *
     * @param array $data
     * @return Liquidation
     * @throws ValidationException
     */
    public function createLiquidation(array $data): Liquidation
    {
        $validated = $this->validateData($data);
        
        return DB::transaction(function () use ($validated) {
            $this->calculateFields($validated);
            return Liquidation::create($validated);
        });
    }

    /**
     * Actualiza una liquidación existente con validación y recálculos.
     *
     * @param Liquidation $liquidation
     * @param array $data
     * @return Liquidation
     * @throws ValidationException
     */
    public function updateLiquidation(Liquidation $liquidation, array $data): Liquidation
    {
        $validated = $this->validateData($data, $liquidation);
        
        return DB::transaction(function () use ($liquidation, $validated) {
            $this->calculateFields($validated);
            $liquidation->update($validated);
            return $liquidation->fresh();
        });
    }

    /**
     * Realiza los cálculos financieros automáticos.
     *
     * @param array &$data
     */
    protected function calculateFields(array &$data): void
    {
        // Cálculo del monto real a entregar
        $data['real_to_deliver'] = 
            $data['initial_cash'] 
            + $data['total_collected'] 
            - $data['total_expenses'] 
            - $data['new_credits'];

        // Cálculo de faltante/sobrante
        $difference = $data['real_to_deliver'] - $data['base_delivered'];
        
        $data['shortage'] = max(0, -$difference);
        $data['surplus'] = max(0, $difference);
        
        // Calcular efectivo entregado (ajustado por faltante/sobrante)
        $data['cash_delivered'] = $data['base_delivered'] + $data['surplus'] - $data['shortage'];
    }

    /**
     * Valida los datos de liquidación.
     *
     * @param array $data
     * @param Liquidation|null $liquidation
     * @return array
     * @throws ValidationException
     */
    protected function validateData(array $data, ?Liquidation $liquidation = null): array
    {
        $rules = [
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id',
            'collection_target' => 'required|numeric|min:0',
            'initial_cash' => 'required|numeric|min:0',
            'base_delivered' => 'required|numeric|min:0',
            'total_collected' => 'required|numeric|min:0',
            'total_expenses' => 'required|numeric|min:0',
            'new_credits' => 'required|numeric|min:0',
            'status' => 'sometimes|in:pending,approved,rejected',
        ];

        return Validator::make($data, $rules)->validate();
    }

    /**
     * Cierra una liquidación cambiando su estado.
     *
     * @param Liquidation $liquidation
     * @param string $status
     * @return Liquidation
     */
    public function closeLiquidation(Liquidation $liquidation, string $status): Liquidation
    {
        $validStatuses = ['approved', 'rejected'];
        
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Estado inválido para cierre");
        }

        $liquidation->update(['status' => $status]);
        return $liquidation;
    }

    public function getLiquidationsBySeller(int $sellerId, Request $request, int $perPage = 20)
{
    try {
        $query = Liquidation::with(['seller'])
            ->where('seller_id', $sellerId);

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        } 

        
        $query->orderBy('created_at', 'desc');

        $liquidations = $query->paginate($perPage);

        return $this->successResponse([
            'success' => true,
            'message' => 'Liquidaciones obtenidas exitosamente',
            'data' => $liquidations
        ]);

    } catch (\Exception $e) {
        Log::error($e->getMessage());
        return $this->errorResponse('Error al obtener las liquidaciones', 500);
    }
}


     /**
     * Aplica filtros adicionales a la consulta
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        // Filtro por rango de fechas
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date', [
                $filters['start_date'],
                $filters['end_date']
            ]);
        }

        // Filtro por estado
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtro por faltantes
        if (isset($filters['has_shortage'])) {
            $query->where('shortage', '>', 0);
        }

        // Filtro por sobrantes
        if (isset($filters['has_surplus'])) {
            $query->where('surplus', '>', 0);
        }

        // Filtro por búsqueda general
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('status', 'like', $searchTerm)
                  ->orWhere('date', 'like', $searchTerm);
            });
        }
    }

    /**
     * Obtiene estadísticas de liquidaciones para un vendedor
     *
     * @param int $sellerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSellerStats(int $sellerId)
    {
        try {
            $stats = [
                'total_liquidations' => Liquidation::where('seller_id', $sellerId)->count(),
                'pending_count' => Liquidation::where('seller_id', $sellerId)
                    ->where('status', 'pending')->count(),
                'average_collected' => Liquidation::where('seller_id', $sellerId)
                    ->avg('total_collected'),
                'total_shortage' => Liquidation::where('seller_id', $sellerId)
                    ->sum('shortage'),
                'total_surplus' => Liquidation::where('seller_id', $sellerId)
                    ->sum('surplus'),
            ];

            return $this->successResponse([
                'success' => true,
                'message' => "Estadísticas obtenidas con éxito",
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener estadísticas', 500);
        }
    }
}