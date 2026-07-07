<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LiquidationService
{
    const TIMEZONE = 'America/Lima';

    protected $metricsCacheService;

    public function __construct(MetricsCacheService $metricsCacheService)
    {
        $this->metricsCacheService = $metricsCacheService;
    }

    use ApiResponse;

    /**
     * Garantiza que un string sea YYYY-MM-DD (solo dígitos y guiones en las
     * posiciones esperadas) antes de interpolarlo en un subquery raw. Usado
     * en los métodos getAccumulatedBy* que arman correlated subqueries.
     */
    private function assertDateFormat(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("Formato de fecha inválido: {$date}");
        }
    }
    /**
     * Crea una nueva liquidación con validación y cálculos automáticos.
     *
     * @param array $data
     * @return Liquidation
     * @throws ValidationException
     */
    public function createLiquidation(array $data): Liquidation
    {
        if (isset($data['timezone']) && !empty($data['timezone'])) {
            $data['created_at'] = Carbon::now($data['timezone']);
            $data['updated_at'] = Carbon::now($data['timezone']);
            unset($data['timezone']);
        }
        $validated = $this->validateData($data);

        return DB::transaction(function () use ($validated) {
            $this->calculateFields($validated);
            $liquidation = Liquidation::create($validated);

            // Recalcular esta liquidación y las siguientes para asegurar integridad
            $this->recalculateLiquidation($liquidation->seller_id, $liquidation->date->toDateString());
            $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date->toDateString());

            // Notificación de sobrante/faltante
            $sellerConfig = \App\Models\SellerConfig::where('seller_id', $validated['seller_id'])->first();
            if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
                $seller = Seller::with(['user', 'company'])->find($validated['seller_id']);
                $companyId = $seller->company_id;

                // Filtrar administradores: SuperAdmin (1) ve todo, Admin (2) solo su empresa
                $admins = \App\Models\User::where(function($query) use ($companyId) {
                    $query->where('role_id', 1)
                          ->orWhere(function($q) use ($companyId) {
                              $q->where('role_id', 2)
                                ->whereHas('company', function($c) use ($companyId) {
                                    $c->where('id', $companyId);
                                });
                          });
                })->get();

                $userToNotify = $seller->user;
                if ($validated['shortage'] > 0) {
                    $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un faltante de $' . number_format($validated['shortage'], 2) . ' en la liquidación del ' . $validated['date'] . '.';
                    $link = '/dashboard/liquidaciones/' . $liquidation->id;
                    $data = [
                        'liquidation_id' => $liquidation->id,
                        'seller_id' => $seller->id,
                        'shortage' => $validated['shortage'],
                        'date' => $validated['date'],
                    ];
                    $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                    foreach ($admins as $admin) {
                        $admin->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                    }
                }
                if ($validated['surplus'] > 0) {
                    $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un sobrante de $' . number_format($validated['surplus'], 2) . ' en la liquidación del ' . $validated['date'] . '.';
                    $link = '/dashboard/liquidaciones/' . $liquidation->id;
                    $data = [
                        'liquidation_id' => $liquidation->id,
                        'seller_id' => $seller->id,
                        'surplus' => $validated['surplus'],
                        'date' => $validated['date'],
                    ];
                    $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                    foreach ($admins as $admin) {
                        $admin->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                    }
                }
            }

            return $liquidation;
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
        if (isset($data['timezone']) && !empty($data['timezone'])) {
            $data['updated_at'] = Carbon::now($data['timezone']);
            unset($data['timezone']);
        }
        $validated = $this->validateData($data, $liquidation);

        return DB::transaction(function () use ($liquidation, $validated) {
            $this->calculateFields($validated);
            $liquidation->update($validated);

            // Invalida el caché después de actualizar
            $this->metricsCacheService->invalidateLiquidationMetrics($liquidation->seller_id, $liquidation->date->toDateString());

            // Recalcular esta liquidación y las siguientes
            $this->recalculateLiquidation($liquidation->seller_id, $liquidation->date->toDateString());
            $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date->toDateString());

            $liquidation->refresh();
            $changedData = $liquidation->getChanges();

            // Notificación de sobrante/faltante si está activo en SellerConfig
            $sellerConfig = \App\Models\SellerConfig::where('seller_id', $validated['seller_id'])->first();
            if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
                $seller = Seller::with(['user', 'company'])->find($validated['seller_id']);
                $companyId = $seller->company_id;

                // Filtrar administradores: SuperAdmin (1) ve todo, Admin (2) solo su empresa
                $admins = \App\Models\User::where(function($query) use ($companyId) {
                    $query->where('role_id', 1)
                          ->orWhere(function($q) use ($companyId) {
                              $q->where('role_id', 2)
                                ->whereHas('company', function($c) use ($companyId) {
                                    $c->where('id', $companyId);
                                });
                          });
                })->get();

                $userToNotify = $seller->user;
                if ($validated['shortage'] > 0) {
                    $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un faltante de $' . number_format($validated['shortage'], 2) . ' en la liquidación del ' . $validated['date'] . '.';
                    $link = '/dashboard/liquidaciones/' . $liquidation->id;
                    $data = [
                        'liquidation_id' => $liquidation->id,
                        'seller_id' => $seller->id,
                        'shortage' => $validated['shortage'],
                        'date' => $validated['date'],
                    ];
                    $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                    foreach ($admins as $admin) {
                        $admin->notify(new \App\Notifications\GeneralNotification('Alerta de faltante en liquidación', $message, $link, $data));
                    }
                }
                if ($validated['surplus'] > 0) {
                    $message = 'Alerta: El vendedor ' . $seller->user->name . ' tiene un sobrante de $' . number_format($validated['surplus'], 2) . ' en la liquidación del ' . $validated['date'] . '.';
                    $link = '/dashboard/liquidaciones/' . $liquidation->id;
                    $data = [
                        'liquidation_id' => $liquidation->id,
                        'seller_id' => $seller->id,
                        'surplus' => $validated['surplus'],
                        'date' => $validated['date'],
                    ];
                    $userToNotify->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                    foreach ($admins as $admin) {
                        $admin->notify(new \App\Notifications\GeneralNotification('Alerta de sobrante en liquidación', $message, $link, $data));
                    }
                }
            }

            return $liquidation->fresh();
        });
    }

    /**
     * Cálculo PROVISORIO de real_to_deliver/faltante/sobrante para el alta.
     *
     * NO es la fórmula autoritativa: createLiquidation y updateLiquidation llaman
     * a recalculateLiquidation() inmediatamente después, que sobreescribe estos
     * valores con los de calculateLiquidationMetrics() (la ÚNICA fuente de verdad
     * de la caja). Se mantiene solo para dejar el registro inicial coherente
     * antes del recálculo; no editar la fórmula acá sin tocar la canónica.
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

    public function approve($id, $timezone = null)
    {
        try {
            $user = Auth::user();
            $liquidation = Liquidation::findOrFail($id);

            if ($user->role_id != 1 && $user->role_id != 2) {
                return $this->errorResponse('No tienes permisos para aprobar liquidaciones', 403);
            }

            if ($liquidation->status === 'approved') {
                return $this->errorResponse('La liquidación ya ha sido aprobada previamente.', 422);
            }

            // Verificar que el vendedor haya cerrado la caja (status debe ser 'pending', 'auto' o 'En curso')
            if (!in_array($liquidation->status, ['pending', 'auto', 'En curso'])) {
                return $this->errorResponse(
                    'No se puede aprobar esta liquidación porque no está en estado pendiente o automático.',
                    422
                );
            }

            $previousUnapproved = Liquidation::where('seller_id', $liquidation->seller_id)
                ->where('date', '<', $liquidation->date)
                ->where('status', '!=', 'approved')
                ->orderBy('date', 'asc')
                ->first();

            if ($previousUnapproved) {
                return $this->errorResponse(
                    "Para aprobar esta liquidación debes cerrar primero la liquidación pendiente del día {$previousUnapproved->date}.",
                    422
                );
            }

            $updateData = [
                'status' => 'approved',
                'end_date' => $timezone ? Carbon::now($timezone) : now()
            ];
            $liquidation->update($updateData);

            $this->recalculateLiquidation($liquidation->seller_id, $liquidation->date);

            // Recalcula todas las liquidaciones posteriores
            $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date);

            return $this->successResponse([
                'success' => true,
                'message' => 'Liquidación cerrada y aprobada correctamente.',
                'data' => $liquidation
            ]);
        } catch (\Exception $e) {
            \Log::error("Error en approve: " . $e->getMessage());
            return $this->errorResponse('Error al aprobar la liquidación', 500);
        }
    }

    public function approveMultiple($ids, $timezone = null)
    {
        try {
            $user = Auth::user();

            // Trae las liquidaciones en orden de fecha ASC para asegurar la secuencia
            $liquidations = Liquidation::whereIn('id', $ids)
                ->orderBy('date', 'asc')
                ->get();

            foreach ($liquidations as $liquidation) {
                if ($user->role_id != 1 && $user->role_id != 2) {
                    return $this->errorResponse('No tienes permisos para aprobar liquidaciones', 403);
                }

                if ($liquidation->status === 'approved') {
                    continue; // Ya aprobada, la saltamos
                }

                // Chequea la secuencia: ¿hay alguna anterior sin aprobar y que no esté en $ids?
                $previousUnapproved = Liquidation::where('seller_id', $liquidation->seller_id)
                    ->where('date', '<', $liquidation->date)
                    ->where('status', '!=', 'approved')
                    ->whereNotIn('id', $ids)
                    ->orderBy('date', 'asc')
                    ->first();

                if ($previousUnapproved) {
                    return $this->errorResponse(
                        "Para aprobar la liquidación del día {$liquidation->date} debes aprobar primero la liquidación pendiente del día {$previousUnapproved->date}.",
                        422
                    );
                }

                $updateData = [
                    'status' => 'approved',
                    'end_date' => $timezone ? Carbon::now($timezone) : now()
                ];
                $liquidation->update($updateData);

                $this->recalculateLiquidation($liquidation->seller_id, $liquidation->date);

                // Recalcula todas las liquidaciones posteriores para cada liquidación aprobada
                $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Liquidaciones aprobadas correctamente.',
                'data' => $liquidations
            ]);
        } catch (\Exception $e) {
            \Log::error("Error en approveMultiple: " . $e->getMessage());
            return $this->errorResponse('Error al aprobar las liquidaciones', 500);
        }
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
            'initial_cash' => 'required|numeric',
            'base_delivered' => 'required|numeric|min:0',
            'cash_delivered' => 'nullable|numeric',
            'total_collected' => 'required|numeric|min:0',
            'total_expenses' => 'required|numeric|min:0',
            'new_credits' => 'required|numeric|min:0',
            'status' => 'sometimes|in:pending,approved,rejected',
            'path' => 'nullable|string',
            'observation' => 'nullable|string',
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
    public function closeLiquidation(Liquidation $liquidation, string $status, $timezone = null): Liquidation
    {
        $validStatuses = ['approved', 'rejected'];

        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Estado inválido para cierre");
        }

        $updateData = ['status' => $status];
        if ($timezone) {
            $updateData['updated_at'] = Carbon::now($timezone);
        }
        $liquidation->update($updateData);
        return $liquidation;
    }

    public function getLiquidationsBySeller(int $sellerId, Request $request)
    {
        try {
            $query = Liquidation::with(['seller', 'seller.city.country', 'seller.user', 'closedByUser:id,name,role_id'])
                ->where('seller_id', $sellerId);

            $timezone = $request->get('timezone', 'America/Lima');
            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->get('start_date'), $timezone)->startOfDay()->setTimezone('UTC');
                $endDate = Carbon::parse($request->get('end_date'), $timezone)->endOfDay()->setTimezone('UTC');
                $query->whereBetween('date', [$startDate, $endDate]);
            }

            $query->orderBy('date', 'desc');

            $liquidations = $query->get();

            // Removed automatic recalculation to prevent lock wait timeouts
            // foreach ($liquidations as $liq) {
            //     if ($liq->status !== 'approved') {
            //         $this->recalculateLiquidation($sellerId, $liq->date);
            //     }
            // }

            $lastApprovedLiquidation = Liquidation::where('seller_id', $sellerId)
                ->where('status', 'approved')
                ->orderBy('date', 'desc')
                ->first();

            if ($lastApprovedLiquidation) {
                $lastApprovedDate = $lastApprovedLiquidation->date;
            } else {
                $seller = Seller::find($sellerId);
                $lastApprovedDate = $seller ? $seller->created_at->toDateString() : null;
            }
            $seller = Seller::find($sellerId);
            $sellerDate = $seller ? $seller->created_at->toDateString() : null;

            return $this->successResponse([
                'success' => true,
                'message' => 'Liquidaciones obtenidas exitosamente',
                'data' => $liquidations,
                'seller_liquidation' => $lastApprovedLiquidation ? true : false,
                'last_approved_liquidation_date' => $lastApprovedDate,
                'seller_initial_date' => isset($seller) ? $seller->created_at->toDateString() : null,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
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

    public function recalculateNextLiquidations($sellerId, $fromDate)
    {
        // Busca todas las liquidaciones posteriores
        $liquidations = Liquidation::where('seller_id', $sellerId)
            ->where('date', '>', $fromDate)
            ->orderBy('date', 'asc')
            ->get();

        foreach ($liquidations as $liquidation) {
            // Usamos la lógica completa de recalculateLiquidation para cada una
            $this->recalculateLiquidation($sellerId, $liquidation->date->format('Y-m-d'));
        }
    }

    /**
     * Simula el recálculo en cascada sin guardar en base de datos.
     */
    public function simulateRecalculation($sellerId, $fromDate, $timezone = 'America/Lima')
    {
        // 1. Obtener la liquidación del día del cambio (el disparador)
        $startLiquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $fromDate)
            ->first();

        if (!$startLiquidation)
            return [];

        // 2. Obtener todas las liquidaciones desde esa fecha en adelante
        $liquidations = Liquidation::where('seller_id', $sellerId)
            ->where('date', '>=', $fromDate)
            ->orderBy('date', 'asc')
            ->get();

        $simulation = [];
        $runningRealToDeliver = null;

        foreach ($liquidations as $liq) {
            $dateStr = $liq->date->format('Y-m-d');

            // Si no es el primer día de la simulación, el initial_cash es el real_to_deliver proyectado del anterior
            $simulatedInitialCash = ($runningRealToDeliver !== null) ? $runningRealToDeliver : $liq->initial_cash;

            $metrics = $this->calculateLiquidationMetrics($sellerId, $dateStr, $simulatedInitialCash, $timezone);

            $simulation[] = [
                'date' => $dateStr,
                'original' => [
                    'initial_cash' => (float) $liq->initial_cash,
                    'real_to_deliver' => (float) $liq->real_to_deliver,
                    'shortage' => (float) $liq->shortage,
                    'surplus' => (float) $liq->surplus,
                ],
                'simulated' => [
                    'initial_cash' => (float) $metrics['initial_cash'],
                    'real_to_deliver' => (float) $metrics['real_to_deliver'],
                    'shortage' => (float) $metrics['shortage'],
                    'surplus' => (float) $metrics['surplus'],
                ],
                'diff' => (float) ($metrics['real_to_deliver'] - $liq->real_to_deliver)
            ];

            $runningRealToDeliver = $metrics['real_to_deliver'];
        }

        return $simulation;
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
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener estadísticas', 500);
        }
    }

    public function getLiquidationData($sellerId, $date, $userId, $timezone = null, $autoCreate = true)
    {
        $tz = $timezone ?: self::TIMEZONE;
        $startUTC = Carbon::parse($date, $tz)->startOfDay()->setTimezone('UTC');
        $endUTC = Carbon::parse($date, $tz)->endOfDay()->setTimezone('UTC');

        // 1. Verificar si ya existe liquidación para esta fecha (usando el campo 'date')
        $existingLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
            ->whereDate('date', $date)  // Cambiado de 'created_at' a 'date'
            ->first();

        // Si existe liquidación, retornar directamente esos datos
        if ($existingLiquidation) {
            $todayStr = Carbon::now($tz)->toDateString();
            $liquidationDateStr = Carbon::parse($existingLiquidation->date)->toDateString();
            
            if ($liquidationDateStr == $todayStr) {
                $this->recalculateLiquidation($sellerId, $date, $timezone);
                $updatedLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
                    ->whereDate('date', $date)
                    ->first();
                return $this->formatLiquidationResponse($updatedLiquidation, true);
            } else {
                $this->recalculateLiquidation($sellerId, $date);
                $updatedLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
                    ->whereDate('date', $date)
                    ->first();
                return $this->formatLiquidationResponse($updatedLiquidation, true);
            }
        }

        // Si no existe y es para HOY, y autoCreate está activo, crearla automáticamente (Apertura)
        if ($autoCreate) {
            $todayStr = Carbon::now($tz)->toDateString();
            if ($date == $todayStr) {
                \Log::info("Auto-creando liquidación (Apertura) para vendedor $sellerId en fecha $date");
                $newLiquidation = $this->getOrCreateLiquidation($sellerId, $date, $timezone);
                return $this->formatLiquidationResponse($newLiquidation, true);
            }
        }
        // 2. Obtener datos del endpoint dailyPaymentTotals
        $dailyTotals = $this->getDailyTotals($sellerId, $date, $userId, $tz);

        // 3. Obtener última liquidación para saldo inicial
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $startUTC)
            ->orderBy('date', 'desc')
            ->first();

        $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

        $baseDelivered = (isset($existingLiquidation) && isset($existingLiquidation->base_delivered))
            ? $existingLiquidation->base_delivered
            : 0.00;



        // Créditos irrecuperables actualizados hoy en horario Venezuela
        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        $poliza = (float) Credit::where('seller_id', $sellerId)
            ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
            ->whereNull('deleted_at')
            ->whereNull('unification_reason')
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        \Log::debug($dailyTotals['total_renewal_disbursed']);

        $realToDeliver = $initialCash
            + (
            $dailyTotals['total_income']
            + $dailyTotals['collected_total']
            + $baseDelivered + $poliza
        )
            - (
            $dailyTotals['created_credits_value']
            + $dailyTotals['total_expenses'] + $dailyTotals['total_renewal_disbursed']
        );

        $cashcollection = (
            $dailyTotals['total_income']
            + $dailyTotals['collected_total']
            + $baseDelivered + $poliza
        )
            - (
            $dailyTotals['created_credits_value']
            + $dailyTotals['total_expenses'] + $dailyTotals['total_renewal_disbursed']
        );

        \Log::debug("cashcollection: " . $cashcollection);

        \Log::debug("realToDeliver: " . $realToDeliver);
        // 5. Estructurar respuesta completa
        return [
            'collection_target' => $dailyTotals['daily_goal'],
            'initial_cash' => $initialCash,
            'base_delivered' => $existingLiquidation ? $existingLiquidation->base_delivered : "0.00",
            'total_collected' => $dailyTotals['collected_total'],
            'total_expenses' => $dailyTotals['total_expenses'],
            'total_income' => $dailyTotals['total_income'],
            'new_credits' => $dailyTotals['created_credits_value'],
            'real_to_deliver' => $realToDeliver,
            'cash_collection' => $cashcollection,
            'date' => $date,
            'seller_id' => $sellerId,
            'cash' => $dailyTotals['cash'],
            'transfer' => $dailyTotals['transfer'],
            'expected_total' => $dailyTotals['expected_total'],
            'current_balance' => $dailyTotals['current_balance'],
            'total_clients' => $dailyTotals['total_clients'],
            'existing_liquidation' => null,
            'last_liquidation' => $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null,
            'is_new' => true,
            'liquidation_start_date' => $dailyTotals['liquidation_start_date'],
            'total_crossed_credits' => $dailyTotals['total_crossed_credits'],
            'total_renewal_disbursed' => $dailyTotals['total_renewal_disbursed'],
            'poliza' => $poliza,
            'irrecoverable_credits' => $irrecoverableCredits,
            'total_pending_absorbed' => $dailyTotals['total_crossed_credits'],
            'clients_paid_count' => $dailyTotals['clients_paid_count'] ?? 0,
            'clients_without_credit_count' => $dailyTotals['clients_without_credit_count'] ?? 0,
            'new_clients_count' => $dailyTotals['new_clients_count'] ?? 0,
            'active_clients_with_credit_count' => $dailyTotals['active_clients_with_credit_count'] ?? 0,
            'clients_liquidated_count' => $dailyTotals['clients_liquidated_count'] ?? 0,
            'clients_full_payment_count' => $dailyTotals['clients_full_payment_count'] ?? 0,
            'clients_partial_payment_count' => $dailyTotals['clients_partial_payment_count'] ?? 0,
            'clients_liquidated_and_renewed_count' => $dailyTotals['clients_liquidated_and_renewed_count'] ?? 0,
            // Claves faltantes para evitar errores en frontend
            'audits' => [],
            'path' => '',
            'end_date' => null,
        ];
    }

    /**
     * Get or create a liquidation record for a seller and date.
     * Useful for logging audits before a formal close.
     */
    public function getOrCreateLiquidation($sellerId, $date, $timezone = null)
    {
        $tz = $timezone ?: self::TIMEZONE;

        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)
            ->first();

        if ($liquidation) {
            return $liquidation;
        }

        // Create a draft liquidation
        $seller = Seller::with('city.country')->find($sellerId);
        $userId = $seller ? $seller->user_id : null;
        $country = $seller?->city?->country ?? null;
        $currency = $country?->currency ?? 'PEN'; // Fallback a PEN si no hay país

        // Importante: autoCreate = false para evitar recursión infinita
        $dynamicData = $this->getLiquidationData($sellerId, $date, $userId, $tz, false);

        try {
            return Liquidation::create([
            'seller_id' => $sellerId,
            'date' => $date,
            'currency' => $currency, // ✅ AGREGADO
            'status' => 'En curso',
            'initial_cash' => floatval($dynamicData['initial_cash'] ?? 0),
            'collection_target' => floatval($dynamicData['collection_target'] ?? 0),
            'base_delivered' => 0,
            'total_collected' => floatval($dynamicData['total_collected'] ?? 0),
            'total_expenses' => floatval($dynamicData['total_expenses'] ?? 0),
            'total_income' => floatval($dynamicData['total_income'] ?? 0),
            'new_credits' => floatval($dynamicData['new_credits'] ?? 0),
            'real_to_deliver' => floatval($dynamicData['real_to_deliver'] ?? 0),
            'poliza' => floatval($dynamicData['poliza'] ?? 0),
            'renewal_disbursed_total' => floatval($dynamicData['total_renewal_disbursed'] ?? 0),
            'total_pending_absorbed' => floatval($dynamicData['total_pending_absorbed'] ?? 0),
            'irrecoverable_credits_amount' => floatval($dynamicData['irrecoverable_credits'] ?? 0),
            'clients_paid_count' => intval($dynamicData['clients_paid_count'] ?? 0),
            'clients_without_credit_count' => intval($dynamicData['clients_without_credit_count'] ?? 0),
            'new_clients_count' => intval($dynamicData['new_clients_count'] ?? 0),
            'active_clients_with_credit_count' => intval($dynamicData['active_clients_with_credit_count'] ?? 0),
            'clients_liquidated_count' => intval($dynamicData['clients_liquidated_count'] ?? 0),
            'clients_full_payment_count' => intval($dynamicData['clients_full_payment_count'] ?? 0),
            'clients_partial_payment_count' => intval($dynamicData['clients_partial_payment_count'] ?? 0),
            'clients_liquidated_and_renewed_count' => intval($dynamicData['clients_liquidated_and_renewed_count'] ?? 0),
            'shortage' => 0,
            'surplus' => 0,
            'cash_delivered' => 0,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Carrera: otro proceso creó la misma (seller_id, date) entre el
            // first() de arriba y este create(). El índice único
            // `liquidations_seller_active_date_unique` lo rechaza (error 1062);
            // devolvemos la fila ya existente en vez de duplicar.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $existing = Liquidation::where('seller_id', $sellerId)
                    ->whereDate('date', $date)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * Red de seguridad: asegura que exista la liquidación del día de negocio del
     * vendedor, SIN romper el flujo que la invoca (p. ej. registrar un pago).
     * Es la garantía de que un día de puro cobro nunca quede huérfano si la
     * auto-apertura del login falló. Idempotente: si ya existe, la devuelve.
     *
     * @return Liquidation|null  null si no se pudo (error logueado, no lanzado).
     */
    public function ensureDailyLiquidation($sellerId, $date, $timezone = null): ?Liquidation
    {
        try {
            if (!$timezone) {
                $seller = Seller::with('city.country')->find($sellerId);
                $timezone = \App\Helpers\TimezoneHelper::getSellerTimezone($seller);
            }
            return $this->getOrCreateLiquidation($sellerId, $date, $timezone);
        } catch (\Throwable $e) {
            \Log::error('[liquidation.ensure] no se pudo asegurar la liquidación del día', [
                'seller_id' => $sellerId,
                'date' => $date,
                'timezone' => $timezone,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function recalculateLiquidation($sellerId, $date, $timezone = null)
    {
        if (!$timezone) {
            $timezone = 'America/Lima';
        }

        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)
            ->first();

        if (!$liquidation) {
            return;
        }

        // FREEZE de lo firmado: una liquidación 'approved' es INMUTABLE. No se
        // recalcula —ni por cascada ni por cambios de fórmula—; su
        // real_to_deliver queda como ANCLA de la cadena de caja. Antes el motor
        // reescribía días aprobados en cada recálculo (p.ej. sumaba de vuelta el
        // irrecuperable con la fórmula nueva), moviendo cajas firmadas sin
        // control. Para modificar una aprobada hay que REABRIRLA explícitamente
        // (pasa a 'En curso' y ahí sí vuelve a recalcular).
        if ($liquidation->status === 'approved') {
            return;
        }

        // Obtener métricas calculadas (con autocorrección de initial_cash si es necesario)
        $metrics = $this->calculateLiquidationMetrics($sellerId, $date, null, $timezone);

        // Verificar si hay cambios reales comparando el modelo con las métricas
        $hasChanges = !(
            $liquidation->initial_cash == $metrics['initial_cash'] &&
            $liquidation->total_expenses == $metrics['total_expenses'] &&
            $liquidation->new_credits == $metrics['new_credits'] &&
            $liquidation->total_income == $metrics['total_income'] &&
            $liquidation->total_collected == $metrics['total_collected'] &&
            $liquidation->real_to_deliver == $metrics['real_to_deliver'] &&
            $liquidation->shortage == $metrics['shortage'] &&
            $liquidation->surplus == $metrics['surplus'] &&
            $liquidation->poliza == $metrics['poliza'] &&
            $liquidation->renewal_disbursed_total == $metrics['renewal_disbursed_total'] &&
            $liquidation->total_pending_absorbed == $metrics['total_pending_absorbed'] &&
            $liquidation->clients_paid_count == $metrics['clients_paid_count'] &&
            $liquidation->clients_without_credit_count == $metrics['clients_without_credit_count'] &&
            $liquidation->new_clients_count == $metrics['new_clients_count'] &&
            $liquidation->active_clients_with_credit_count == $metrics['active_clients_with_credit_count'] &&
            $liquidation->clients_liquidated_count == $metrics['clients_liquidated_count'] &&
            $liquidation->clients_full_payment_count == $metrics['clients_full_payment_count'] &&
            $liquidation->clients_partial_payment_count == $metrics['clients_partial_payment_count'] &&
            $liquidation->clients_liquidated_and_renewed_count == $metrics['clients_liquidated_and_renewed_count']
        );

        if (!$hasChanges) {
            return;
        }

        $liquidation->update($metrics);

        // Invalida el caché después de recalcular (formatea a Y-m-d)
        $dateStr = ($date instanceof \Carbon\Carbon) ? $date->toDateString() : (string) $date;
        $this->metricsCacheService->invalidateLiquidationMetrics($sellerId, $dateStr);
    }

    /**
     * Centraliza el cálculo de métricas para una liquidación.
     * Si $forcedInitialCash es null, se calcula automáticamente basado en el día anterior.
     */
    public function calculateLiquidationMetrics($sellerId, $date, $forcedInitialCash = null, $timezone = 'America/Lima')
    {
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)
            ->first();

        if (!$liquidation)
            return [];

        $seller = Seller::find($sellerId);
        $userId = $seller ? $seller->user_id : null;

        $dailyTotals = $this->getDailyTotals($sellerId, $date, $userId, $timezone);

        // Cálculos de la BD
        $totalExpenses = $userId
            ? Expense::where('user_id', $userId)
                ->where('business_date', $date)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('status', 'Aprobado')
                        ->orWhere('description', 'like', '%AJUSTE%');
                })
                ->sum('value')
            : 0;

        $totalIncome = $userId
            ? Income::where('user_id', $userId)
                ->where('business_date', $date)
                ->whereNull('deleted_at')
                ->sum('value')
            : 0;

        $newCredits = Credit::where('seller_id', $sellerId)
            ->whereNull('renewed_from_id')
            ->whereNull('renewed_to_id')
            ->whereNull('deleted_at')
            ->whereNull('unification_reason')
            ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
            ->sum('credit_value');

        $totalCollected = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $date)
            ->whereIn('payments.status', ['Pagado', 'Aprobado', 'Abonado'])
            ->sum('payments.amount');

        $renewalCredits = Credit::where('seller_id', $sellerId)
            ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();

        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = Credit::find($renewCredit->renewed_from_id);
            $pendingAmount = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = max(0, $oldCreditTotal - $oldCreditPaid);
                $total_pending_absorbed += $pendingAmount;
            }
            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;
        }

        // Corte del día por la zona del VENDEDOR (mismos límites UTC que
        // new_credits/poliza), no whereDate(updated_at) que cortaba por fecha UTC
        // y podía tirar el crédito irrecuperable al día equivocado cerca de
        // medianoche.
        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereNull('credits.deleted_at')
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        $poliza = (float) Credit::where('seller_id', $sellerId)
            ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        // Determinar initial_cash
        $initialCash = $forcedInitialCash;
        if ($initialCash === null) {
            $lastLiquidation = Liquidation::where('seller_id', $sellerId)
                ->where('date', '<', $date)
                ->orderBy('date', 'desc')
                ->first();
            $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;
        }

        $realToDeliver = $initialCash
            + $liquidation->base_delivered
            + ($totalIncome + $totalCollected + $poliza)
            - ($totalExpenses
            + $newCredits
            + $total_renewal_disbursed);

        $cashDelivered = $liquidation->cash_delivered;
        $shortage = 0;
        $surplus = 0;

        if ($realToDeliver > 0) {
            if ($cashDelivered < $realToDeliver) {
                $shortage = $realToDeliver - $cashDelivered;
            } else {
                $surplus = $cashDelivered - $realToDeliver;
            }
        } else {
            $debtAmount = abs($realToDeliver);
            if ($cashDelivered > $debtAmount) {
                $surplus = $cashDelivered - $debtAmount;
            } else {
                $shortage = $debtAmount - $cashDelivered;
            }
        }

        return [
            'initial_cash' => $initialCash,
            'total_expenses' => $totalExpenses,
            'new_credits' => $newCredits,
            'total_income' => $totalIncome,
            'total_collected' => $totalCollected,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'poliza' => $poliza,
            'renewal_disbursed_total' => $total_renewal_disbursed,
            'irrecoverable_credits_amount' => $irrecoverableCredits,
            'total_pending_absorbed' => $total_pending_absorbed,
            'clients_paid_count' => $dailyTotals['clients_paid_count'] ?? 0,
            'clients_without_credit_count' => $dailyTotals['clients_without_credit_count'] ?? 0,
            'new_clients_count' => $dailyTotals['new_clients_count'] ?? 0,
            'active_clients_with_credit_count' => $dailyTotals['active_clients_with_credit_count'] ?? 0,
            'clients_liquidated_count' => $dailyTotals['clients_liquidated_count'] ?? 0,
            'clients_full_payment_count' => $dailyTotals['clients_full_payment_count'] ?? 0,
            'clients_partial_payment_count' => $dailyTotals['clients_partial_payment_count'] ?? 0,
            'clients_liquidated_and_renewed_count' => $dailyTotals['clients_liquidated_and_renewed_count'] ?? 0,
        ];
    }



    public function getDailyMovements($sellerId, $date = null, $timezone = null)
    {
        try {
            $tz = $timezone ?: self::TIMEZONE;
            $dateLocal = $date ?: Carbon::now($tz)->toDateString();

            if (!is_numeric($sellerId)) {
                $resolvedSeller = Seller::where('uuid', $sellerId)->first();
                $sellerId = $resolvedSeller ? $resolvedSeller->id : null;
            }

            if (!$sellerId) return ['success' => false, 'message' => 'Vendedor no encontrado'];

            $startUTC = Carbon::parse($dateLocal, $tz)->startOfDay()->setTimezone('UTC');
            $endUTC = Carbon::parse($dateLocal, $tz)->endOfDay()->setTimezone('UTC');

            // Obtener user_id del seller (para gastos e ingresos)
            $seller = Seller::find($sellerId);
            $userId = $seller ? $seller->user_id : null;

            $sellerName = $seller && $seller->user ? $seller->user->name : null;
            // Movimientos: pagos (de créditos del vendedor) - todos los campos relevantes
            $payments = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $sellerId)
                ->whereNull('payments.deleted_at')
                ->where('payments.business_date', $dateLocal)
                ->where('payments.amount', '>', 0)
                ->select('payments.*', 'credits.client_id as client_id')
                ->get()
                ->map(function ($p) use ($sellerName) {
                    $clientName = null;
                    if (!empty($p->client_id)) {
                        $c = Client::find($p->client_id);
                        $clientName = $c ? $c->name : null;
                    }
                    return [
                        'type' => 'payment',
                        'id' => $p->id,
                        'amount' => (float) $p->amount,
                        'created_at' => (string) $p->created_at,
                        'payment_method' => $p->payment_method ?? null,
                        'client_id' => $p->client_id ?? null,
                        'client_name' => $clientName,
                        'seller_id' => $p->seller_id ?? null,
                        'seller_name' => $sellerName,
                        'note' => $p->note ?? null,
                        'raw' => $p,
                    ];
                });

            // Movimientos: gastos (expenses) del user vinculado al seller
            $expenses = collect();
            if ($userId) {
                $expenses = Expense::where('user_id', $userId)
                    ->where('business_date', $dateLocal)
                    ->whereNull('deleted_at')
                    ->get()
                    ->map(function ($e) {
                        return [
                            'type' => 'expense',
                            'movement_kind' => 'Egreso',
                            'id' => $e->id,
                            'amount' => (float) $e->value,
                            'created_at' => (string) $e->created_at,
                            'business_date' => $e->business_date ? (is_string($e->business_date) ? $e->business_date : $e->business_date->format('Y-m-d')) : null,
                            'category_id' => $e->category_id ?? null,
                            'description' => $e->description ?? null,
                            'raw' => $e,
                        ];
                    });
            }

            // Movimientos: ingresos (incomes) del user vinculado al seller
            $incomes = collect();
            if ($userId) {
                $incomes = Income::where('user_id', $userId)
                    ->where('business_date', $dateLocal)
                    ->whereNull('deleted_at')
                    ->get()
                    ->map(function ($i) {
                        return [
                            'type' => 'income',
                            'movement_kind' => 'Ingreso',
                            'id' => $i->id,
                            'amount' => (float) $i->value,
                            'created_at' => (string) $i->created_at,
                            'business_date' => $i->business_date ? (is_string($i->business_date) ? $i->business_date : $i->business_date->format('Y-m-d')) : null,
                            'description' => $i->description ?? null,
                            'raw' => $i,
                        ];
                    });
            }

            $credits = Credit::where('seller_id', $sellerId)
                ->whereNull('renewed_from_id')
                ->whereNull('renewed_to_id')
                ->whereNull('deleted_at')
                ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
                ->get()
                ->map(function ($c) use ($sellerName, $tz) {
                    $clientName = null;
                    if (!empty($c->client_id)) {
                        $cl = Client::find($c->client_id);
                        $clientName = $cl ? $cl->name : null;
                    }
                    // Use imported_at (if exists) as the business date so frontend date-filter works.
                    // imported credits have a historical created_at, but were actually registered today.
                    $displayDate = $c->imported_at
                        ? Carbon::parse($c->imported_at)->setTimezone($tz)->format('Y-m-d')
                        : Carbon::parse($c->created_at)->setTimezone($tz)->format('Y-m-d');
                    return [
                        'type' => 'credit',
                        'movement_kind' => 'Crédito',
                        'id' => $c->id,
                        'amount' => (float) $c->credit_value,
                        'created_at' => (string) $c->created_at,
                        'business_date' => $displayDate,
                        'client_id' => $c->client_id ?? null,
                        'client_name' => $clientName,
                        'seller_name' => $sellerName,
                        'interest_percent' => $c->total_interest ?? 0,
                        'micro_insurance' => $c->micro_insurance_amount ?? 0,
                        'raw' => $c,
                    ];
                });

            $all = $payments->concat($expenses)->concat($incomes)->concat($credits);

            // ── Saldo corriente (extracto tipo banco) ─────────────────────
            // Cada movimiento lleva su EFECTO en la caja (con signo) y el SALDO
            // resultante, arrancando de la caja anterior (initial_cash).
            // Invariante de conciliación: initial_cash + Σefectos == rtd.
            $liqRow = Liquidation::where('seller_id', $sellerId)
                ->whereDate('date', $dateLocal)->first();
            $saldoInicial = $liqRow ? (float) $liqRow->initial_cash : 0.0;
            $rtdGuardado = $liqRow ? (float) $liqRow->real_to_deliver : null;

            // Efecto en la caja con la MISMA convención que
            // calculateLiquidationMetrics: cobro/ingreso suman; gasto resta;
            // crédito resta el efectivo que realmente sale (credit_value menos
            // la póliza retenida).
            $effectOf = function (array $item): float {
                $amt = (float) ($item['amount'] ?? 0);
                switch ($item['type'] ?? '') {
                    case 'payment':
                    case 'income':
                        return $amt;
                    case 'expense':
                        return -$amt;
                    case 'credit':
                        $raw = $item['raw'] ?? null;
                        $pct = $raw ? (float) ($raw->micro_insurance_percentage ?? 0) : 0.0;
                        $poliza = $pct * $amt / 100;
                        return -($amt - $poliza);
                    default:
                        return 0.0;
                }
            };

            // Clave de orden temporal (créditos importados usan imported_at).
            $orderKey = function ($item) use ($tz) {
                try {
                    $raw = $item['raw'] ?? null;
                    $eff = ($raw && isset($raw->imported_at) && $raw->imported_at)
                        ? $raw->imported_at
                        : ($item['created_at'] ?? null);
                    return Carbon::parse($eff)->setTimezone($tz)->timestamp;
                } catch (\Throwable $e) {
                    return 0;
                }
            };

            // Ascendente: acumular el saldo desde la caja anterior.
            $running = $saldoInicial;
            $asc = $all->sortBy($orderKey)->values()->map(function ($item) use (&$running, $effectOf) {
                $ef = $effectOf($item);
                $running += $ef;
                $item['efecto'] = round($ef, 2);
                $item['saldo_despues'] = round($running, 2);
                return $item;
            });
            $saldoFinal = round($running, 2);

            // Descendente para mostrar (más reciente primero); el saldo ya viene calculado.
            $sorted = $asc->sortByDesc($orderKey)->values();

            $cuadra = $rtdGuardado !== null ? (abs($saldoFinal - $rtdGuardado) < 0.01) : null;

            return [
                'success' => true,
                'date' => $dateLocal,
                'timezone' => $tz,
                'saldo_inicial' => round($saldoInicial, 2),
                'saldo_final' => $saldoFinal,
                'rtd_guardado' => $rtdGuardado !== null ? round($rtdGuardado, 2) : null,
                'diferencia' => $rtdGuardado !== null ? round($saldoFinal - $rtdGuardado, 2) : null,
                'cuadra' => $cuadra,
                'count' => $sorted->count(),
                'data' => $sorted,
            ];
        } catch (\Exception $e) {
            \Log::error("Error en getDailyMovements: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener movimientos del día',
                'error' => $e->getMessage(),
            ];
        }
    }
    protected function getDailyTotals($sellerId, $date, $userId, $timezone = null)
    {
        $tz = $timezone ?: self::TIMEZONE;
        $startUTC = Carbon::parse($date, $tz)->startOfDay()->setTimezone('UTC');
        $endUTC = Carbon::parse($date, $tz)->endOfDay()->setTimezone('UTC');

        $query = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $date)
            ->where('credits.seller_id', $sellerId)
            ->groupBy('payments.payment_method');

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(DB::raw('MIN(payments.business_timestamp) as first_payment_date'))
            ->whereNull('payments.deleted_at')
            ->where('payments.business_date', $date);

        if ($sellerId) {
            $firstPaymentQuery->where('credits.seller_id', $sellerId);
        }

        $firstPaymentResult = $firstPaymentQuery->first();
        $firstPaymentDate = null;
        if ($firstPaymentResult && $firstPaymentResult->first_payment_date) {
            $firstPaymentDate = Carbon::parse($firstPaymentResult->first_payment_date)
                ->setTimezone($tz)
                ->toDateTimeString();
        }

        $paymentResults = $query->get();

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0,
            'base_value' => 0,
            'liquidation_start_date' => $firstPaymentDate,
            'clients_paid_count' => 0,
            'clients_without_credit_count' => 0,
            'new_clients_count' => 0,
            'active_clients_with_credit_count' => 0,
            'clients_liquidated_count' => 0,
            'clients_full_payment_count' => 0,
            'clients_partial_payment_count' => 0,
            'clients_liquidated_and_renewed_count' => 0
        ];

        foreach ($paymentResults as $result) {
            $amount = (float) $result->total;
            if ($result->payment_method === 'Efectivo') {
                $totals['cash'] = $amount;
            } elseif ($result->payment_method === 'Transferencia') {
                $totals['transfer'] = $amount;
            }
            $totals['collected_total'] += $amount;
        }

        // Obtener total esperado
        $totals['expected_total'] = (float) DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereDate('installments.due_date', $date)
            ->sum('installments.quota_amount');

        // Obtener créditos creados (incluye importados - usa COALESCE(imported_at, created_at))
        $credits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
            ->whereNull('deleted_at')
            ->whereNull('renewed_from_id')
            ->whereNull('unification_reason')
            ->select([
                    DB::raw('COALESCE(SUM(credit_value), 0) as value'),
                    DB::raw('COALESCE(SUM(
                    CASE
                        WHEN total_interest IS NOT NULL AND total_interest > 0
                        THEN credit_value * (total_interest / 100)
                        ELSE 0
                    END
                ), 0) as interest')
                ])
            ->first();

        $totals['created_credits_value'] = (float) $credits->value;
        $totals['created_credits_interest'] = (float) $credits->interest;

        // Obtener el user_id correcto (del vendedor)
        $targetUserId = $userId;
        if ($sellerId) {
            $sellerObj = Seller::find($sellerId);
            if ($sellerObj && $sellerObj->user_id) {
                $targetUserId = $sellerObj->user_id;
            }
        }

        // Obtener gastos
        $totals['total_expenses'] = (float) Expense::where('user_id', $targetUserId)
            ->where('business_date', $date)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('status', 'Aprobado')
                    ->orWhere('description', 'like', '%AJUSTE%');
            })
            ->sum('value');

        $totals['total_income'] = (float) Income::where('user_id', $targetUserId)
            ->where('business_date', $date)
            ->whereNull('deleted_at')
            ->sum('value');

        // Obtener total clientes
        $totals['total_clients'] = (int) DB::table('clients')
            ->whereExists(function ($query) use ($sellerId) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->where('credits.seller_id', $sellerId);
            })
            ->count();

        // 1. Cantidad de clientes con créditos y cuando pagaron durante del día
        $totals['clients_paid_count'] = (int) DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('payments.business_date', $date)
            ->whereNull('payments.deleted_at')
            ->distinct('credits.client_id')
            ->count('credits.client_id');

        // 2. Cuantos clientes sin créditos
        $totals['clients_without_credit_count'] = (int) DB::table('clients')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->whereNotIn('credits.status', ['Liquidado', 'Rechazado', 'Anulado', 'Finalizado']);
            })
            ->count();

        // 3. Cantidad de clientes nuevos
        $totals['new_clients_count'] = (int) DB::table('clients')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('deleted_at')
            ->count();

        // 4. Cantidad de clientes activos con créditos (Vigentes/Atrasados/etc, NO Liquidados)
        $totals['active_clients_with_credit_count'] = (int) DB::table('clients')
            ->where('seller_id', $sellerId)
            ->whereNull('deleted_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->whereIn('credits.status', ['Vigente', 'Atrasado', 'Mora', 'Renovado']);
            })
            ->count();

        // 5. Cantidad de clientes que liquidaron hoy (L)
        // Clientes que hicieron un pago hoy y su crédito quedó en estado 'Liquidado'
        $totals['clients_liquidated_count'] = (int) DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('payments.business_date', $date)
            ->where('credits.status', 'Liquidado')
            ->whereNull('payments.deleted_at')
            ->distinct('credits.client_id')
            ->count('credits.client_id');

        // 6. Cantidad de clientes con pagos completos vs parciales
        $paymentsToday = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('payments.business_date', $date)
            ->whereNull('payments.deleted_at')
            ->select('credits.client_id', 'payments.status')
            ->get();

        $clientPayments = $paymentsToday->groupBy('client_id');
        $fullPaymentClients = 0;
        $partialPaymentClients = 0;

        foreach ($clientPayments as $clientId => $payments) {
            $hasFull = $payments->contains(function ($p) {
                return in_array($p->status, ['Pagado', 'Aprobado']);
            });

            if ($hasFull) {
                $fullPaymentClients++;
            } else {
                $partialPaymentClients++;
            }
        }

        $totals['clients_full_payment_count'] = $fullPaymentClients;
        $totals['clients_partial_payment_count'] = $partialPaymentClients;

        // 7. Clientes que liquidaron Y renovaron hoy
        // Clientes que tienen un crédito liquidado hoy Y un crédito nuevo creado hoy
        $liquidatedClientIds = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('payments.business_date', $date)
            ->where('credits.status', 'Liquidado')
            ->whereNull('payments.deleted_at')
            ->distinct('credits.client_id')
            ->pluck('credits.client_id')
            ->toArray();

        $totals['clients_liquidated_and_renewed_count'] = (int) DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereIn('client_id', $liquidatedClientIds)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('renewed_from_id') // Crédito nuevo "puro" o primera compra tras liquidar
            ->whereNull('unification_reason')
            ->whereNull('deleted_at')
            ->distinct('client_id')
            ->count();

        // Calcular saldos
        $totals['daily_goal'] = $totals['expected_total'];
        $totals['current_balance'] = $totals['collected_total'] - $totals['total_expenses'];

        /*         \Log::info('Cálculo de créditos cruzados - Parámetros:', [
            'seller_id' => $sellerId,
            'date' => $date,
        ]); */

        // === Detalle de renovaciones ===
        $renewalCredits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();

        $detalles_renovaciones = [];
        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();

            $pendingAmount = 0;
            $oldCreditTotal = 0;
            $oldCreditPaid = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                $total_pending_absorbed += $pendingAmount;
            }

            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;

            $detalles_renovaciones[] = [
                'NuevoCreditoID' => $renewCredit->id,
                'MontoTotalNuevo_Y' => $renewCredit->credit_value,
                'SaldoPendienteAbsorbido' => $pendingAmount,
                'DesembolsoNeto' => $netDisbursement,
                'ClienteID' => $renewCredit->client_id,
                'CreditoAnteriorID' => $renewCredit->renewed_from_id,
            ];
        }

        $totals['poliza'] = (float) DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNull('deleted_at')
            /*    ->whereNull('unification_reason') */
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));


        $totals['total_renewal_disbursed'] = $total_renewal_disbursed;
        $totals['total_crossed_credits'] = $total_pending_absorbed;
        ;
        $totals['detalle_renovaciones'] = $detalles_renovaciones;

        // Log detallado
        /*    \Log::info('Desglose de renovaciones:', [
            'detalle_renovaciones' => $detalles_renovaciones,
            'total_renewal_disbursed' => $total_renewal_disbursed
        ]); */

        return $totals;
    }

    protected function formatLiquidationResponse($liquidation, $isExisting = false)
    {
        $firstPaymentDate = null;
        if ($isExisting) {
            $firstPaymentQuery = DB::table('payments')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->select('payments.payment_date', 'payments.created_at') // 👈 aquí
                ->whereDate('payments.created_at', $liquidation->date)
                ->where('credits.seller_id', $liquidation->seller_id)
                ->orderBy('payments.created_at', 'asc')
                ->first();


            if ($firstPaymentQuery) {
                $firstPaymentDate = $firstPaymentQuery->created_at;
            }
        }

        $dailyTotals = $this->getDailyTotals($liquidation->seller_id, $liquidation->date, $liquidation->user_id ?? null);

        $cashCollection = (
            $liquidation->total_income
            + $liquidation->total_collected
            + $liquidation->base_delivered
            + $liquidation->poliza
        )
            - (
            $liquidation->new_credits
            + $liquidation->total_expenses
            + $liquidation->renewal_disbursed_total
        );
        \Log::debug('Liquidation object:', ['liquidation' => json_decode(json_encode($liquidation), true)]);

        return [
            'collection_target' => $liquidation->collection_target,
            'initial_cash' => $liquidation->initial_cash,
            'base_delivered' => $liquidation->base_delivered,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'total_income' => $liquidation->total_income,
            'new_credits' => $liquidation->new_credits,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'date' => $liquidation->date,
            'seller_id' => $liquidation->seller_id,
            'path' => $liquidation->path,
            'existing_liquidation' => $isExisting ? $this->formatLiquidationDetails($liquidation) : null,
            'last_liquidation' => $this->getPreviousLiquidation($liquidation->seller_id, $liquidation->date),
            'is_new' => false,
            'poliza' => $liquidation->poliza,
            'liquidation_start_date' => $firstPaymentDate,
            'cash_collection' => $cashCollection,
            'total_pending_absorbed' => $liquidation->total_pending_absorbed,
            'total_crossed_credits' => $dailyTotals['total_crossed_credits'],
            'total_renewal_disbursed' => $dailyTotals['total_renewal_disbursed'],
            'audits' => $liquidation->audits->map(function ($audit) {
                return [
                    'id' => $audit->id,
                    'user_id' => $audit->user_id,
                    'user_name' => optional($audit->user)->name,
                    'action' => $audit->action,
                    'changes' => $audit->changes,
                    'created_at' => $audit->created_at->format('Y-m-d H:i:s')
                ];
            })->values(),
            'end_date' => $liquidation->end_date,

        ];
    }
    protected function formatLiquidationDetails($liquidation)
    {
        return [
            'id' => $liquidation->id,
            'date' => $liquidation->date,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'total_income' => $liquidation->total_income,
            'new_credits' => $liquidation->new_credits,
            'base_delivered' => $liquidation->base_delivered,
            'shortage' => $liquidation->shortage,
            'poliza' => $liquidation->poliza,
            'surplus' => $liquidation->surplus,
            'cash_delivered' => $liquidation->cash_delivered,
            'status' => $liquidation->status,
            'created_at' => $liquidation->created_at,
            'end_date' => $liquidation->end_date,
        ];
    }
    protected function getPreviousLiquidation($sellerId, $date)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');

        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $startUTC)
            ->orderBy('date', 'desc')
            ->first();

        return $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null;
    }

    public function getReportByCity($startDate, $endDate, $companyId = null)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');

        $citiesQuery = DB::table('cities');
        if ($companyId !== null) {
            $citiesQuery->whereExists(function ($query) use ($companyId) {
                $query->select(DB::raw(1))
                    ->from('sellers')
                    ->whereColumn('sellers.city_id', 'cities.id')
                    ->where('sellers.company_id', $companyId);
            });
        }
        $cities = $citiesQuery->get();
        $report = [];

        foreach ($cities as $city) {
            $liquidations = Liquidation::whereHas('seller', function ($q) use ($city, $companyId) {
                $q->where('city_id', $city->id);
                if ($companyId !== null) {
                    $q->where('company_id', $companyId);
                }
            })
                ->whereBetween('date', [$startUTC, $endUTC])
                ->get();

            if ($liquidations->count() > 0) {
                $previous_cash = Liquidation::whereHas('seller', function ($q) use ($city, $companyId) {
                    $q->where('city_id', $city->id);
                    if ($companyId !== null) {
                        $q->where('company_id', $companyId);
                    }
                })
                    ->where('status', 'approved')
                    ->where('date', '<', $startUTC)
                    ->orderBy('date', 'desc')
                    ->value('initial_cash') ?? 0;

                $collected = $liquidations->sum('total_collected');
                $loans = $liquidations->sum('new_credits');
                $expenses = $liquidations->sum('total_expenses');
                $income = $liquidations->sum('total_income');
                $current_cash = $liquidations->last()?->cash_delivered ?? 0;

                $expenseCategories = [
                    'ALMUERZO',
                    'EXTORSION',
                    'GASOLINA',
                    'MANTENIMIENTO MOTO',
                    'PAGO DE PLAN',
                    'RETIRO DE SOCIOS',
                    'PASAJES'
                ];
                $city_expenses = [];
                foreach ($expenseCategories as $categoryName) {
                    $categoryId = DB::table('categories')->where('name', $categoryName)->value('id');
                    $city_expenses[$categoryName] = Expense::where('category_id', $categoryId)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('value');
                }

                $income = Income::whereBetween('created_at', [$startUTC, $endUTC])
                    ->whereHas('user', function ($q) use ($city, $companyId) {
                        $q->where('city_id', $city->id);
                        if ($companyId !== null) {
                            $q->where('company_id', $companyId);
                        }
                    })->sum('value');

                $report[] = [
                    'city' => $city->name,
                    'previous_cash' => $previous_cash,
                    'collected' => $collected,
                    'loans' => $loans,
                    'expenses' => $expenses,
                    'ingresos' => $income,
                    'gastos_categoria' => $city_expenses,
                    'current_cash' => $current_cash,
                ];
            }
        }
        return $report;
    }
    public function getAccumulatedByCity($startDate, $endDate, $companyId = null, $sellerIds = null)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->format('Y-m-d');
        $endUTC = Carbon::parse($endDate, $timezone)->format('Y-m-d');

        // Guardrail anti-injection: estos valores se interpolan dentro de un
        // subquery correlacionado (no se puede bindear con `?` por el patrón
        // de DB::raw múltiple). Carbon::parse(...)->format('Y-m-d') ya
        // garantiza el formato, pero validamos explícitamente para que
        // cualquier futura regresión rompa ruidoso antes de abrir SQLi.
        $this->assertDateFormat($startUTC);
        $this->assertDateFormat($endUTC);

        \Log::debug("getAccumulatedByCity - Rango UTC:", ['startUTC' => $startUTC, 'endUTC' => $endUTC, 'company_id' => $companyId]);

        // BUG HISTÓRICO CORREGIDO:
        // Antes este método hacía `SUM(initial_cash)` sobre TODAS las filas
        // de liquidations del rango. Eso es contablemente incorrecto:
        // `initial_cash` es un SALDO (caja inicial del día), no un flujo.
        // En un rango de 14 días con 11 vendedores, sumaba 14×11 = 154
        // cajas iniciales y multiplicaba ~4-5× el valor real.
        //
        // El fix sigue la misma regla que getAccumulatedBySellersInCity:
        // por cada seller tomamos la caja inicial del PRIMER día con
        // liquidación aprobada en el rango (es el saldo de apertura), y
        // luego sumamos entre sellers para el agregado por ciudad.
        //
        // Implementación: subquery `per_seller` agrega los flujos por
        // seller y resuelve su initial_cash con un correlated subquery.
        // El query externo agrupa por ciudad.

        $perSellerSub = DB::table('liquidations as l')
            ->join('sellers as s', 'l.seller_id', '=', 's.id')
            ->select(
                's.city_id as city_id',
                'l.seller_id as seller_id',
                DB::raw('SUM(l.total_collected) as total_collected'),
                DB::raw('SUM(l.total_expenses) as total_expenses'),
                DB::raw('SUM(l.new_credits) as new_credits'),
                DB::raw('SUM(l.base_delivered) as base_delivered'),
                DB::raw('SUM(l.real_to_deliver) as real_to_deliver'),
                DB::raw('SUM(l.shortage) as shortage'),
                DB::raw('SUM(l.surplus) as surplus'),
                DB::raw('SUM(l.cash_delivered) as cash_delivered'),
                DB::raw("(SELECT l2.initial_cash FROM liquidations l2
                          WHERE l2.seller_id = l.seller_id
                            AND l2.date >= '$startUTC'
                            AND l2.date <= '$endUTC'
                            AND l2.status = 'approved'
                          ORDER BY l2.date ASC LIMIT 1) as initial_cash")
            )
            ->whereBetween('l.date', [$startUTC, $endUTC])
            ->where('l.status', 'approved');

        if ($companyId !== null) {
            $perSellerSub->where('s.company_id', $companyId);
        }
        if ($sellerIds !== null) {
            $perSellerSub->whereIn('s.id', $sellerIds);
        }
        $perSellerSub->groupBy('s.city_id', 'l.seller_id');

        $query = DB::table(DB::raw('(' . $perSellerSub->toSql() . ') as per_seller'))
            ->mergeBindings($perSellerSub)
            ->join('cities', 'cities.id', '=', 'per_seller.city_id')
            ->select(
                'cities.name as city_name',
                'cities.id as city_id',
                // COALESCE blinda el agregado: si un seller no tuvo
                // liquidación 'approved' en el rango, el subquery devuelve
                // NULL → SUM(NULL) sería NULL en MySQL. Forzamos 0 para
                // que el frontend no muestre vacío y los cálculos posteriores
                // (utilidad, márgenes) no propaguen NULL.
                DB::raw('COALESCE(SUM(per_seller.total_collected), 0) as total_collected'),
                DB::raw('COALESCE(SUM(per_seller.total_expenses), 0) as total_expenses'),
                DB::raw('COALESCE(SUM(per_seller.new_credits), 0) as new_credits'),
                DB::raw('COALESCE(SUM(per_seller.initial_cash), 0) as initial_cash'),
                DB::raw('COALESCE(SUM(per_seller.base_delivered), 0) as base_delivered'),
                DB::raw('COALESCE(SUM(per_seller.real_to_deliver), 0) as real_to_deliver'),
                DB::raw('COALESCE(SUM(per_seller.shortage), 0) as shortage'),
                DB::raw('COALESCE(SUM(per_seller.surplus), 0) as surplus'),
                DB::raw('COALESCE(SUM(per_seller.cash_delivered), 0) as cash_delivered')
            )
            ->groupBy('cities.id', 'cities.name');

        \Log::debug("getAccumulatedByCity - SQL:", ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);

        $result = $query->get();
        \Log::debug("getAccumulatedByCity - Resultado:", ['count' => $result->count(), 'data' => $result]);
        return $result;
    }

    public function getAccumulatedBySellerInCity($cityId, $startDate, $endDate, $companyId = null, $sellerIds = null)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->format('Y-m-d');
        $endUTC = Carbon::parse($endDate, $timezone)->format('Y-m-d');
        $this->assertDateFormat($startUTC);
        $this->assertDateFormat($endUTC);

        $query = DB::table('liquidations')
            ->join('sellers', 'liquidations.seller_id', '=', 'sellers.id')
            ->join('cities', 'sellers.city_id', '=', 'cities.id')
            ->join('users', 'sellers.user_id', '=', 'users.id')
            ->select(
                'sellers.id as seller_id',
                'sellers.seller_id as seller_code',
                'users.name as seller_name',
                DB::raw('SUM(liquidations.total_collected) as total_collected'),
                DB::raw('SUM(liquidations.total_expenses) as total_expenses'),
                DB::raw('SUM(liquidations.new_credits) as new_credits'),
                DB::raw("(SELECT l2.initial_cash FROM liquidations l2 WHERE l2.seller_id = sellers.id AND l2.date >= '$startUTC' AND l2.date <= '$endUTC' AND l2.status = 'approved' ORDER BY l2.date ASC LIMIT 1) as initial_cash"),
                DB::raw('SUM(liquidations.base_delivered) as base_delivered'),
                DB::raw('SUM(liquidations.real_to_deliver) as real_to_deliver'),
                DB::raw('SUM(liquidations.shortage) as shortage'),
                DB::raw('SUM(liquidations.surplus) as surplus'),
                DB::raw('SUM(liquidations.cash_delivered) as cash_delivered')
            )
            ->where('cities.id', $cityId)
            // Filtramos por status 'approved' para mantener consistencia
            // con getAccumulatedByCity y getAccumulatedBySellersInCity.
            // Antes este método mezclaba liquidaciones en 'En curso',
            // 'auto', 'pending', inflando los totales.
            ->where('liquidations.status', 'approved')
            ->whereBetween('liquidations.date', [$startUTC, $endUTC]);

        if ($companyId !== null) {
            $query->where('sellers.company_id', $companyId);
        }

        if ($sellerIds !== null) {
            $query->whereIn('sellers.id', $sellerIds);
        }

        return $query->groupBy('sellers.id', 'sellers.seller_id', 'users.name')
            ->get();
    }

    public function getAccumulatedBySellersInCity($cityId, $startDate, $endDate, $companyId = null, $sellerIds = null)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->format('Y-m-d');
        $endUTC = Carbon::parse($endDate, $timezone)->format('Y-m-d');
        $this->assertDateFormat($startUTC);
        $this->assertDateFormat($endUTC);

        $query = DB::table('liquidations')
            ->join('sellers', 'liquidations.seller_id', '=', 'sellers.id')
            ->join('cities', 'sellers.city_id', '=', 'cities.id')
            ->join('users', 'sellers.user_id', '=', 'users.id')
            ->select(
                'sellers.id as seller_id',
                'users.name as seller_name',
                'cities.name as city_name',
                DB::raw('SUM(liquidations.total_collected) as total_collected'),
                DB::raw('SUM(liquidations.total_expenses) as total_expenses'),
                DB::raw('SUM(liquidations.new_credits) as new_credits'),
                DB::raw("(SELECT l2.initial_cash FROM liquidations l2 WHERE l2.seller_id = sellers.id AND l2.date >= '$startUTC' AND l2.date <= '$endUTC' AND l2.status = 'approved' ORDER BY l2.date ASC LIMIT 1) as initial_cash"),
                DB::raw('SUM(liquidations.base_delivered) as base_delivered'),
                DB::raw('SUM(liquidations.real_to_deliver) as real_to_deliver'),
                DB::raw('SUM(liquidations.shortage) as shortage'),
                DB::raw('SUM(liquidations.surplus) as surplus'),
                DB::raw('SUM(liquidations.cash_delivered) as cash_delivered'),
                DB::raw('COUNT(liquidations.id) as liquidation_count')
            )
            ->where('cities.id', $cityId)
            ->where('liquidations.status', 'approved')
            ->whereBetween('liquidations.date', [$startUTC, $endUTC]);

        if ($companyId !== null) {
            $query->where('sellers.company_id', $companyId);
        }

        if ($sellerIds !== null) {
            $query->whereIn('sellers.id', $sellerIds);
        }

        return $query->groupBy('sellers.id', 'users.name', 'cities.name')
            ->get();
    }

    public function getSellerLiquidationsDetail($sellerId, $startDate, $endDate)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->format('Y-m-d');
        $endUTC = Carbon::parse($endDate, $timezone)->format('Y-m-d');

        return Liquidation::with(['seller', 'seller.user'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->orderBy('date', 'asc')
            ->get();
    }

    public function reopenRoute($sellerId, $date, $request)
    {
        // 1. Obtener la zona horaria real del vendedor
        $seller = \App\Models\Seller::with('city.country')->find($sellerId);
        $timezone = $seller->city->country->timezone ?? 'America/Lima';

        // 2. Verificar restricción de tiempo (23:59:59 del día de la liquidación)
        // La fecha de la liquidación ($date)
        $liquidationDate = Carbon::parse($date)->format('Y-m-d');
        
        // Hora actual en la zona del vendedor
        $nowInSellerTimezone = Carbon::now($timezone);
        $endOfLiquidationDay = Carbon::parse($liquidationDate, $timezone)->endOfDay();

        // Validar: Si "ahora" es mayor que fin del día de la liquidación, bloquear.
        if ($nowInSellerTimezone->gt($endOfLiquidationDay)) {
            return [
                'success' => false, // Importante para el frontend
                'message' => 'No se puede reabrir la caja: El tiempo límite (23:59:59 hora local) ha expirado.',
                'audits_deleted' => 0
            ];
        }

        $dateLocal = \Carbon\Carbon::parse($date, $timezone)->format('Y-m-d');
        $startUTC = \Carbon\Carbon::parse($dateLocal, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC = \Carbon\Carbon::parse($dateLocal, $timezone)->endOfDay()->setTimezone('UTC');


        $liquidation = \App\Models\Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $dateLocal)
            ->orderBy('id', 'desc')
            ->first();
        \Log::debug("Reopening route - Liquidation found:", ['liquidation' => $liquidation]);

        if (!$liquidation) {
            return ['success' => false, 'message' => 'No existe liquidación para ese vendedor y fecha', 'audits_deleted' => 0];
        }

        // Regla: No reabrir si ya fue aprobada por el superadministrador
        if ($liquidation->status === 'approved') {
            return [
                'success' => false,
                'message' => 'El superadministrador ya cerró esta liquidación y no se puede reabrir.',
                'audits_deleted' => 0
            ];
        }

        $seller = \App\Models\Seller::find($liquidation->seller_id);
        $userId = $seller ? $seller->user_id : null;

        \Log::debug("Reopening route - User ID:", ['userId' => $userId]);

        $deleted = \App\Models\LiquidationAudit::where('liquidation_id', $liquidation->id)
            ->where('user_id', $userId)
            ->whereIn('action', ['updated', 'created'])
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->delete();

        \Log::debug("Reopening route - Audits deleted:", ['deleted' => $deleted]);

        // Reapertura SIN borrar la fila.
        //
        // Antes se hacia $liquidation->delete() (soft-delete) para "abrir" el
        // dia. Efecto colateral: si el vendedor NO volvia a cerrar, la
        // liquidacion quedaba soft-deleted y el dia DESAPARECIA del listado
        // (dia huerfano / caso Nazaret).
        //
        // Ahora la revertimos a estado abierto ('En curso') conservando la
        // fila:
        //   - 'En curso' NO bloquea movimientos (assertSellerCashOpen solo
        //     bloquea pending/auto/approved) => la caja queda operable igual.
        //   - El dia SIGUE listado (deleted_at NULL) como "por cerrar".
        //   - Al re-cerrar, getLiquidationData encuentra esta misma fila y el
        //     front va por updateLiquidation (no crea otra) => compatible con
        //     el indice unico (una sola fila activa por dia).
        $liquidation->update([
            'status'         => 'En curso',
            'closed_at'      => null,
            'closed_by'      => null,
            'closed_by_role' => null,
        ]);

        \App\Models\LiquidationAudit::create([
            'liquidation_id' => $liquidation->id,
            'user_id'        => $userId,
            'action'         => 'updated',
            'changes'        => [
                'accion' => 'reapertura',
                'estado' => 'En curso',
            ],
            'created_at'     => now(),
        ]);

        return [
            'message' => 'Ruta reabierta correctamente',
            'audits_deleted' => $deleted
        ];
    }

    public function getLiquidationHistory($sellerId, $startDate, $endDate)
    {
        $history = \App\Models\Liquidation::with(['expenses', 'credits'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc')
            ->get();
        return $history;
    }

    /**
     * Descarga una liquidación individual en PDF o Excel
     * @param int $liquidationId
     * @param string $format ('pdf'|'excel')
     * @param string $timezone
     * @return \Illuminate\Http\Response
     */
    public function downloadLiquidationReport($liquidationId, $format = 'pdf', $timezone = 'America/Lima')
    {
        $liquidation = Liquidation::with(['seller', 'seller.user', 'seller.city.country'])->find($liquidationId);
        if (!$liquidation) {
            return response()->make('Liquidación no encontrada', 404);
        }

        // Generar el reporte detallado usando la fecha y el vendedor de la liquidación
        $reportDate = $liquidation->date;
        $sellerId = $liquidation->seller_id;
        $user = $liquidation->seller->user;

        $reportData = $this->generateDailyReportByLiquidation($reportDate, $sellerId, $user, $timezone);

        $sellerName = $user->name ?? 'vendedor';
        $dateStr = \Carbon\Carbon::parse($reportDate)->format('Y-m-d');
        $safeSellerName = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $sellerName);

        if ($format === 'pdf') {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('liquidations.report', [
                'report' => $reportData,
                'liquidation' => $liquidation,
                'expenses' => $reportData['expenses'] ?? [],
                'incomes' => $reportData['incomes'] ?? [],
            ]);
            return response()->make($pdf->stream(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="liquidacion_' . $safeSellerName . '_' . $dateStr . '.pdf"',
            ]);
        } elseif ($format === 'excel') {
            if (!class_exists(\App\Exports\LiquidationExport::class)) {
                throw new \RuntimeException('The LiquidationExport class does not exist. Please create it in the App\Exports namespace.');
            }
            $export = new \App\Exports\LiquidationExport($reportData);
            return response()->make(\Maatwebsite\Excel\Facades\Excel::download($export, 'liquidacion_' . $safeSellerName . '_' . $dateStr . '.xlsx')->getFile()->getContent(), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="liquidacion_' . $safeSellerName . '_' . $dateStr . '.xlsx"',
            ]);
        } else {
            return response('Formato no soportado', 400);
        }
    }

    public function generateDailyReportByLiquidation($date, $sellerId, $user, $timezone = 'America/Lima')
    {
        $dateOnly = substr($date, 0, 10);
        $reportDate = Carbon::createFromFormat('Y-m-d', $dateOnly, $timezone);
        $start = $reportDate->copy()->startOfDay()->setTimezone('America/Lima')->setTimezone('UTC');
        $end = $reportDate->copy()->endOfDay()->setTimezone('America/Lima')->setTimezone('UTC');

        $creditsQuery = Credit::with(['client', 'installments', 'payments'])
            ->whereHas('payments', function ($query) use ($dateOnly) {
                $query->where('payments.business_date', $dateOnly);
            });
        if ($sellerId) {
            $creditsQuery->whereHas('client', function ($query) use ($sellerId) {
                $query->where('seller_id', $sellerId);
            });
        }
        $credits = $creditsQuery->get();

        $expensesQuery = Expense::whereBetween('expenses.created_at', [$start, $end]);
        if ($user) {
            $expensesQuery->where('user_id', $user->id);
        }
        $expenses = $expensesQuery->get();
        $totalExpenses = $expenses->sum('value');

        $incomesQuery = Income::whereBetween('incomes.created_at', [$start, $end]);
        if ($user) {
            $incomesQuery->where('user_id', $user->id);
        }
        $incomes = $incomesQuery->get();
        $totalIncomes = $incomes->sum('value');

        $reportData = [];
        $totalCollected = 0;
        $withPayment = 0;
        $withoutPayment = 0;
        $totalCapital = 0;
        $totalInterest = 0;
        $totalMicroInsurance = 0;
        $capitalCollected = 0;
        $interestCollected = 0;
        $microInsuranceCollected = 0;

        foreach ($credits as $index => $credit) {
            $interestAmount = $credit->credit_value * ($credit->total_interest / 100);
            $quotaAmount = ($credit->credit_value + $interestAmount) / $credit->number_installments;
            $totalCreditValue = $credit->credit_value + $interestAmount;
            $totalPaid = $credit->payments->sum('amount');
            $remainingAmount = $totalCreditValue - $totalPaid;
            $dayPayments = $credit->payments()->whereBetween('payments.created_at', [$start, $end])->get();
            $paidToday = $dayPayments->sum('amount');
            $paymentTime = $dayPayments->isNotEmpty() ? $dayPayments->last()->created_at->timezone(self::TIMEZONE)->format('H:i:s') : null;

            if ($paidToday > 0) {
                $withPayment++;
            } else {
                $withoutPayment++;
            }

            $totalCollected += $paidToday;
            $totalCapital += $credit->credit_value;
            $totalInterest += $interestAmount;
            $totalMicroInsurance += $credit->micro_insurance_amount;

            $totalCreditAmount = $credit->credit_value + $interestAmount;
            if ($totalCreditAmount > 0) {
                $capitalRatio = $credit->credit_value / $totalCreditAmount;
                $interestRatio = $interestAmount / $totalCreditAmount;
                $microInsuranceRatio = $credit->micro_insurance_amount / $totalCreditAmount;
            } else {
                $capitalRatio = $interestRatio = $microInsuranceRatio = 0;
            }

            $capitalCollected += $paidToday * $capitalRatio;
            $interestCollected += $paidToday * $interestRatio;
            $microInsuranceCollected += $paidToday * $microInsuranceRatio;

            $reportData[] = [
                'no' => $index + 1,
                'client_name' => $credit->client->name,
                'credit_id' => $credit->id,
                'payment_frequency' => $credit->payment_frequency,
                'capital' => $credit->credit_value,
                'interest' => $interestAmount,
                'micro_insurance' => $credit->micro_insurance_amount,
                'total_credit' => $totalCreditValue,
                'quota_amount' => $quotaAmount,
                'remaining_amount' => $remainingAmount,
                'paid_today' => $paidToday,
                'payment_time' => $paymentTime,
            ];
        }

        $newCredits = Credit::whereBetween('credits.created_at', [$start, $end])
            ->whereNull('renewed_from_id');
        if ($sellerId) {
            $newCredits->whereHas('client', function ($query) use ($sellerId) {
                $query->where('seller_id', $sellerId);
            });
        }
        $newCredits = $newCredits->get();
        $totalNewCredits = $newCredits->sum('credit_value');

        $netUtility = $totalCollected + $totalIncomes - $totalExpenses;
        $netAmount = $totalCollected - $totalExpenses;
        $netUtilityPlusCapital = $netUtility + $totalCapital;

        return [
            'report_date' => $date,
            'report_data' => $reportData,
            'total_collected' => $totalCollected,
            'with_payment' => $withPayment,
            'without_payment' => $withoutPayment,
            'total_credits' => count($reportData),
            'new_credits' => $newCredits,
            'total_new_credits' => $totalNewCredits,
            'seller' => $sellerId ? Seller::find($sellerId) : null,
            'user' => $user,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'incomes' => $incomes,
            'total_incomes' => $totalIncomes,
            'total_capital' => $totalCapital,
            'total_interest' => $totalInterest,
            'total_micro_insurance' => $totalMicroInsurance,
            'capital_collected' => $capitalCollected,
            'interest_collected' => $interestCollected,
            'microinsurance_collected' => $microInsuranceCollected,
            'net_utility' => $netUtility,
            'net_utility_plus_capital' => $netUtilityPlusCapital,
        ];
    }

    /**
     * Obtiene la fecha de la primera liquidación aprobada de cada vendedor (seller).
     * Si no tiene liquidaciones aprobadas, devuelve la fecha de creación del seller.
     *
     * @return array
     */
    public function getFirstApprovedLiquidationBySeller($user = null)
    {
        $query = Seller::with(['user', 'city.country']);

        if ($user) {
            if ($user->role_id == 2) {
                $companyId = $user->company ? $user->company->id : -1;
                $query->where('company_id', $companyId);
            } elseif ($user->role_id == 5) {
                $query->where('user_id', $user->id);
            }
        }

        $sellers = $query->get();
        $result = [];
        foreach ($sellers as $seller) {
            $firstApproved = Liquidation::where('seller_id', $seller->id)
                ->where('status', 'approved')
                ->orderBy('date', 'asc')
                ->first();
            $result[] = [
                'seller_id' => $seller->id,
                'seller_name' => $seller->user ? $seller->user->name : null,
                'city' => $seller->city ? $seller->city->name : null,
                'country' => ($seller->city && $seller->city->country) ? $seller->city->country->name : null,
                'first_approved_liquidation_date' => $firstApproved ? $firstApproved->date : $seller->created_at->toDateString(),
            ];
        }
        return $result;
    }

    /**
     * Devuelve el detalle de una liquidación con totalizadores y listados paginados de créditos nuevos, pagos, gastos e ingresos.
     * @param int $liquidationId
     * @param Request $request
     * @return array
     */
    public function getLiquidationDetail($liquidationId, Request $request)
    {
        $liquidation = Liquidation::with(['seller', 'seller.user', 'seller.city.country'])->find($liquidationId);
        if (!$liquidation) {
            return [
                'success' => false,
                'message' => 'Liquidación no encontrada',
                'status_code' => 404
            ];
        }

        // Totalizadores
        $previousLiquidation = Liquidation::where('seller_id', $liquidation->seller_id)
            ->where('date', '<', $liquidation->date)
            ->orderBy('date', 'desc')
            ->first();
        $cajaAnterior = $previousLiquidation ? $previousLiquidation->real_to_deliver : 0;
        $cajaActual = $liquidation->real_to_deliver;
        $ingresos = $liquidation->total_income;
        $egresos = $liquidation->total_expenses;
        $creditosNuevos = $liquidation->new_credits;
        $baseEntregada = $liquidation->base_delivered;

        // Paginación
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        // Créditos del día (Nuevos Y Renovaciones para el listado unificado)
        $creditosNuevosQuery = \App\Models\Credit::with('client')
            ->where('seller_id', $liquidation->seller_id)
            ->whereNull('renewed_to_id')
            ->whereNull('unification_reason')
            ->whereBetween('created_at', [
                    Carbon::parse($liquidation->date, self::TIMEZONE)->startOfDay()->setTimezone('UTC'),
                    Carbon::parse($liquidation->date, self::TIMEZONE)->endOfDay()->setTimezone('UTC')
                ]);
        
        // Clonar para paginación sin afectar el query base
        $creditosNuevosPaginados = (clone $creditosNuevosQuery)->paginate($perPage, ['*'], 'creditos_page', $page);
        
        // Lógica "Smart Classification": Detectar clientes recurrentes (que ya tuvieron créditos antes)
        $creditosNuevosPaginados->getCollection()->transform(function ($credit) {
            if (!$credit->renewed_from_id) {
                // Si no es renovación estricta, verificamos historia previa
                // Buscamos si existe al menos UN crédito anterior de este mismo cliente
                $hasHistory = \App\Models\Credit::where('client_id', $credit->client_id)
                    ->where('id', '<', $credit->id) // Anterior a este
                    ->exists();
                
                $credit->setAttribute('is_returning', $hasHistory);
            } else {
                $credit->setAttribute('is_returning', false);
            }
            return $credit;
        });

        // Obtener todos los registros para cálculos (clonando)
        $allCredits = (clone $creditosNuevosQuery)->get();
        
        // Lógica "Smart Classification": Detectar clientes recurrentes (que ya tuvieron créditos antes)
        // Aplicamos esto también a $allCredits para que los totales coincidan con la vista
        $allCredits->transform(function ($credit) {
            if (!$credit->renewed_from_id) {
                // Buscamos si existe al menos UN crédito anterior de este mismo cliente
                $hasHistory = \App\Models\Credit::where('client_id', $credit->client_id)
                    ->where('id', '<', $credit->id)
                    ->exists();
                
                $credit->setAttribute('is_returning', $hasHistory);
            } else {
                $credit->setAttribute('is_returning', false);
            }
            return $credit;
        });

        // Clasificación para métricas
        $pureNewCredits = $allCredits->filter(function ($value) {
            // Es "Puro Nuevo" si NO es renovación estricta Y NO es recurrente
            return is_null($value->renewed_from_id) && !$value->is_returning;
        });
        
        $renewedOrReturningCredits = $allCredits->filter(function ($value) {
             // Es "Renovado/Recurrente" si ES renovación estricta O ES recurrente
             return !is_null($value->renewed_from_id) || $value->is_returning;
        });

        $creditosNuevosRealCount = $pureNewCredits->count();
        $creditosNuevosRealAmount = $pureNewCredits->sum('credit_value');

        $renovadosCount = $renewedOrReturningCredits->count();
        $renovadosAmount = $renewedOrReturningCredits->sum('credit_value');

        // Calcular la suma de la póliza (de todos los del día mostrados)
        $polizaTotal = $allCredits->sum(function($c) {
            return ($c->credit_value * $c->micro_insurance_percentage) / 100;
        });

        // Pagos (cobrados en esta liquidación)
        // Join con clients para obtener el nombre del cliente
        $pagosQuery = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->where('credits.seller_id', $liquidation->seller_id)
            ->whereBetween('payments.created_at', [
                    Carbon::parse($liquidation->date, self::TIMEZONE)->startOfDay()->setTimezone('UTC'),
                    Carbon::parse($liquidation->date, self::TIMEZONE)->endOfDay()->setTimezone('UTC')
                ])
            ->select('payments.*', 'clients.name as client_name', 'credits.id as credit_id', 'credits.status as credit_status');
            
        // FIX: Especificar columnas en paginate para evitar ambigüedad por los joins
        $pagosPaginados = (clone $pagosQuery)->paginate($perPage, ['payments.*', 'clients.name as client_name', 'credits.id as credit_id', 'credits.status as credit_status'], 'pagos_page', $page);
        
        // Total recaudo (suma real de los pagos listados) - clonar para evitar limites de paginación
        $clientsPaidAmount = (clone $pagosQuery)->sum('payments.amount');

        // Gastos (egresos de esta liquidación)
        $gastosQuery = \App\Models\Expense::where('user_id', $liquidation->seller->user_id)
            ->whereBetween('created_at', [
                    Carbon::parse($liquidation->date, self::TIMEZONE)->startOfDay()->setTimezone('UTC'),
                    Carbon::parse($liquidation->date, self::TIMEZONE)->endOfDay()->setTimezone('UTC')
                ]);
        $gastosPaginados = $gastosQuery->paginate($perPage, ['*'], 'gastos_page', $page);

        // Ingresos de esta liquidación
        $ingresosQuery = \App\Models\Income::where('user_id', $liquidation->seller->user_id)
            ->whereBetween('created_at', [
                    Carbon::parse($liquidation->date, self::TIMEZONE)->startOfDay()->setTimezone('UTC'),
                    Carbon::parse($liquidation->date, self::TIMEZONE)->endOfDay()->setTimezone('UTC')
                ]);
        $ingresosPaginados = $ingresosQuery->paginate($perPage, ['*'], 'ingresos_page', $page);
        $ingresosCount = $ingresosQuery->count();
        $egresosCount = $gastosQuery->count();
        $creditosNuevosCount = $creditosNuevosQuery->count();

        return [
            'success' => true,
            'message' => 'Detalle de liquidación obtenido correctamente',
            'totals' => [
                'caja_anterior' => $cajaAnterior,
                'caja_actual' => $cajaActual,
                'ingresos' => $ingresos,
                'ingresos_count' => $ingresosCount,
                'egresos' => $egresos,
                'egresos_count' => $egresosCount,
                'creditos_nuevos' => $creditosNuevos, // Valor contable (pure new)
                'creditos_nuevos_count' => $creditosNuevosCount, // Este era el count global, quizás debamos usar $creditosNuevosRealCount si el anterior filtraba? 
                // El anterior $creditosNuevosQuery filtraba renewals.
                // Aqui mantendremos la coherencia: 'creditos_nuevos' del modelo suele ser solo nuevos.
                // Pero para la UI detallada enviamos los desgloses:
                'creditos_nuevos_real_count' => $creditosNuevosRealCount,
                'creditos_nuevos_real_amount' => $creditosNuevosRealAmount,
                'renovados_count' => $renovadosCount,
                'renovados_amount' => $renovadosAmount,
                
                'base_entregada' => $baseEntregada,
                'collection_target' => $liquidation->collection_target,
                'initial_cash' => $liquidation->initial_cash,
                'total_collected' => $liquidation->total_collected,
                'clients_paid_amount' => $clientsPaidAmount, // Nuevo campo visual
                
                'total_expenses' => $liquidation->total_expenses,
                'total_income' => $liquidation->total_income,
                'real_to_deliver' => $liquidation->real_to_deliver,
                'shortage' => $liquidation->shortage,
                'surplus' => $liquidation->surplus,
                'cash_delivered' => $liquidation->cash_delivered,
                'status' => $liquidation->status,
                'end_date' => $liquidation->end_date,
                'renewal_disbursed_total' => $liquidation->renewal_disbursed_total,
                'total_pending_absorbed' => $liquidation->total_pending_absorbed,
                'irrecoverable_credits_amount' => $liquidation->irrecoverable_credits_amount,
                'created_at' => $liquidation->created_at,
                'poliza_total' => $polizaTotal,
            ],
            'creditos_nuevos' => $creditosNuevosPaginados,
            'pagos' => $pagosPaginados,
            'gastos' => $gastosPaginados,
            'ingresos_listado' => $ingresosPaginados,
            'liquidacion' => $liquidation,
        ];
    }

    /**
     * Ajusta los campos de una liquidación (initial_cash, base_delivered, cash_delivered)
     * e inicia un recálculo en cascada.
     * Solo accesible con contraseña y permisos adecuados.
     */
    public function adjustBox(array $data)
    {
        $validator = Validator::make($data, [
            'liquidation_id' => 'required|exists:liquidations,id',
            'password' => 'required|string',
            'observation' => 'required|string|min:10',
            'initial_cash' => 'nullable|numeric',
            'base_delivered' => 'nullable|numeric',
            'cash_delivered' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Validar contraseña de ajuste. SIN default hardcodeado: si no está
        // configurada en el entorno (SYSTEM_ADJUST_PASSWORD), el ajuste queda
        // DESHABILITADO (falla cerrado) en vez de aceptar una clave conocida.
        $systemPassword = env('SYSTEM_ADJUST_PASSWORD');
        if (empty($systemPassword)) {
            throw new \Exception('El ajuste de caja no está habilitado (falta configurar SYSTEM_ADJUST_PASSWORD). Contactar al equipo técnico.');
        }
        if (!hash_equals((string) $systemPassword, (string) $data['password'])) {
            throw new \Exception('Contraseña de ajuste de caja incorrecta.');
        }

        $liquidation = Liquidation::findOrFail($data['liquidation_id']);
        $user = Auth::user();

        // Registrar cambios para la observación
        $changes = [];
        if (isset($data['initial_cash'])) {
            $changes[] = "Saldo Inicial: {$liquidation->initial_cash} -> {$data['initial_cash']}";
            $liquidation->initial_cash = $data['initial_cash'];
        }
        if (isset($data['base_delivered'])) {
            $changes[] = "Base: {$liquidation->base_delivered} -> {$data['base_delivered']}";
            $liquidation->base_delivered = $data['base_delivered'];
        }
        if (isset($data['cash_delivered'])) {
            $changes[] = "Entregado: {$liquidation->cash_delivered} -> {$data['cash_delivered']}";
            $liquidation->cash_delivered = $data['cash_delivered'];
        }

        $userName = $user->name;
        $detail = implode(", ", $changes);
        $newObservation = "AJUSTE MANUAL por {$userName}: {$detail}. Motivo: {$data['observation']}. Fecha: " . now()->toDateTimeString();

        $liquidation->observation = $liquidation->observation
            ? $liquidation->observation . "\n" . $newObservation
            : $newObservation;

        $liquidation->save();

        // Invalida el caché después de un ajuste manual
        $this->metricsCacheService->invalidateLiquidationMetrics($liquidation->seller_id, $liquidation->date->toDateString());

        // Recalcular esta liquidación y las siguientes
        $this->recalculateLiquidation($liquidation->seller_id, $liquidation->date->toDateString());
        $this->recalculateNextLiquidations($liquidation->seller_id, $liquidation->date->toDateString());

        return $liquidation;
    }
}
