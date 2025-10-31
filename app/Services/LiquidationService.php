<?php

namespace App\Services;

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
        if (isset($data['timezone']) && !empty($data['timezone'])) {
            $data['created_at'] = Carbon::now($data['timezone']);
            $data['updated_at'] = Carbon::now($data['timezone']);
            unset($data['timezone']);
        }
        $validated = $this->validateData($data);

        return DB::transaction(function () use ($validated) {
            $this->calculateFields($validated);
            $liquidation = Liquidation::create($validated);

            // Notificación de sobrante/faltante si está activo en SellerConfig
            $sellerConfig = \App\Models\SellerConfig::where('seller_id', $validated['seller_id'])->first();
            if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
                $seller = Seller::find($validated['seller_id']);
                $admins = \App\Models\User::whereIn('role_id', [1, 2])->get();
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

            $liquidation->refresh();
            $changedData = $liquidation->getChanges();

            // Notificación de sobrante/faltante si está activo en SellerConfig
            $sellerConfig = \App\Models\SellerConfig::where('seller_id', $validated['seller_id'])->first();
            if ($sellerConfig && $sellerConfig->notify_shortage_surplus) {
                $seller = Seller::find($validated['seller_id']);
                $admins = \App\Models\User::whereIn('role_id', [1, 2])->get();
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
            $query = Liquidation::with(['seller', 'seller.city.country', 'seller.user'])
                ->where('seller_id', $sellerId);

            if ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->get('start_date'), 'America/Lima')->startOfDay()->setTimezone('UTC');
                $endDate = Carbon::parse($request->get('end_date'), 'America/Lima')->endOfDay()->setTimezone('UTC');
                $query->whereBetween('date', [$startDate, $endDate]);
            }

            $query->orderBy('date', 'desc');

            $liquidations = $query->get();

            foreach ($liquidations as $liq) {
                if ($liq->status !== 'approved') {
                    $this->recalculateLiquidation($sellerId, $liq->date);
                }
            }

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

    protected function recalculateNextLiquidations($sellerId, $fromDate)
    {
        $timezone = 'America/Lima';

        $liquidations = Liquidation::where('seller_id', $sellerId)
            ->where('date', '>', $fromDate)
            ->orderBy('date', 'asc')
            ->get();

        $baseLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', $fromDate)
            ->first();

        $previousRealToDeliver = $baseLiquidation ? $baseLiquidation->real_to_deliver : 0;

        foreach ($liquidations as $liquidation) {
            $initial_cash = $previousRealToDeliver;

            $realToDeliver = $initial_cash +
                ($liquidation->total_income + $liquidation->total_collected) -
                ($liquidation->total_expenses + $liquidation->new_credits + $liquidation->irrecoverable_credits_amount + $liquidation->renewal_disbursed_total);

            $shortage = 0;
            $surplus = 0;

            if ($realToDeliver > 0) {
                if ($liquidation->cash_delivered < $realToDeliver) {
                    $shortage = $realToDeliver - $liquidation->cash_delivered;
                } else {
                    $surplus = $liquidation->cash_delivered - $realToDeliver;
                }
            } else {
                $debtAmount = abs($realToDeliver);

                if ($liquidation->cash_delivered > $debtAmount) {
                    $surplus = $liquidation->cash_delivered - $debtAmount;
                } else {
                    $shortage = $debtAmount - $liquidation->cash_delivered;
                }
            }

            $liquidation->update([
                'initial_cash' => $initial_cash,
                'real_to_deliver' => $realToDeliver,
                'shortage' => $shortage,
                'surplus' => $surplus,
            ]);

            $previousRealToDeliver = $realToDeliver;
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
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener estadísticas', 500);
        }
    }

    public function getLiquidationData($sellerId, $date, $userId, $timezone = null)
    {
        $tz = $timezone ?: self::TIMEZONE;
        $startUTC = Carbon::parse($date, $tz)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $tz)->endOfDay()->setTimezone('UTC');

        // 1. Verificar si ya existe liquidación para esta fecha (usando el campo 'date')
        $existingLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
            ->whereDate('date', $date)  // Cambiado de 'created_at' a 'date'
            ->first();

        // Si existe liquidación, retornar directamente esos datos
        if ($existingLiquidation) {
            $today = Carbon::now($tz)->toDateString(); // Formato 'Y-m-d'
            $liquidationDate = Carbon::parse($existingLiquidation->date)->toDateString();
            \Log::debug("Liquidación existente para fecha $date: $today: $existingLiquidation->date");
            // Solo recalculamos si la liquidación es del día actual (comparando con el campo 'date')
            if ($liquidationDate == $today) {  // Comparar con el campo 'date' de la liquidación
                \Log::debug("Recalculando liquidación para el vendedor $sellerId en la fecha $date (hoy)");
                $this->recalculateLiquidation($sellerId, $date);

                // Vuelve a obtener la liquidación actualizada
                $updatedLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
                    ->whereDate('date', $date)  // Cambiado de 'created_at' a 'date'
                    ->first();

                \Log::debug("Liquidación actualizada: ", $updatedLiquidation->toArray());
                return $this->formatLiquidationResponse($updatedLiquidation, true);
            } else {
                $this->recalculateLiquidation($sellerId, $date);

                // Vuelve a obtener la liquidación actualizada
                $updatedLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
                    ->whereDate('date', $date)  // Cambiado de 'created_at' a 'date'
                    ->first();
                \Log::debug("Liquidación existente para fecha pasada, no se recalcula. Fecha: $existingLiquidation->date");
                return $this->formatLiquidationResponse($updatedLiquidation, true);
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

        \Log::debug($dailyTotals['total_renewal_disbursed']);

        $realToDeliver = $initialCash
            + (
                $dailyTotals['total_income']
                + $dailyTotals['collected_total']
                + $baseDelivered
            )
            - (
                $dailyTotals['created_credits_value']
                + $dailyTotals['total_expenses'] + $dailyTotals['total_renewal_disbursed'] + $irrecoverableCredits
            );

        $cashcollection = (
            $dailyTotals['total_income']
            + $dailyTotals['collected_total']
            + $baseDelivered
        )
            - (
                $dailyTotals['created_credits_value']
                + $dailyTotals['total_expenses'] + $dailyTotals['total_renewal_disbursed'] + $irrecoverableCredits
            );

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
        ];
    }

    public function recalculateLiquidation($sellerId, $date)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        /*     \Log::debug("=== INICIO recalculateLiquidation ===");
        \Log::debug("Seller ID: $sellerId, Fecha: $date");
        \Log::debug("Rango UTC: $startUTC a $endUTC"); */

        // Busca la liquidación del vendedor en esa fecha usando whereDate para coincidir con getLiquidationData
        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)  // CAMBIADO: whereBetween por whereDate
            ->first();

        if (!$liquidation) {
            /*   \Log::debug("❌ NO se encontró liquidación para recálculo");
            \Log::debug("Consulta ejecutada: seller_id = $sellerId, date = $date"); */
            return;
        }

        /* \Log::debug("✅ Liquidación encontrada - ID: {$liquidation->id}");
        \Log::debug("Fecha liquidación: {$liquidation->date}"); */

        // 1. Obtener el user_id del vendedor
        $seller = Seller::find($sellerId);
        $userId = $seller ? $seller->user_id : null;
        /*  \Log::debug("User ID del vendedor: $userId"); */

        // 2. Recalcula los totales actuales desde la BD
        $totalExpenses = $userId
            ? Expense::where('user_id', $userId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->sum('value')
            : 0;

        $totalIncome = $userId
            ? Income::where('user_id', $userId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->sum('value')
            : 0;

        $newCredits = Credit::where('seller_id', $sellerId)
            ->whereNull('renewed_from_id')
            ->whereNull('renewed_to_id')
            ->whereNull('unification_reason')
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->sum('credit_value');

        $totalCollected = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->sum('payments.amount');

        /*  \Log::debug("Nuevos valores calculados desde BD:");
        \Log::debug("- totalExpenses: $totalExpenses");
        \Log::debug("- totalIncome: $totalIncome");
        \Log::debug("- newCredits: $newCredits");
        \Log::debug("- totalCollected: $totalCollected"); */

        // === Detalle de renovaciones ===
        $renewalCredits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereDate('created_at', $date)
            ->whereNotNull('renewed_from_id')
            ->get();

        /* \Log::debug("Créditos de renovación encontrados: " . $renewalCredits->count()); */

        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        foreach ($renewalCredits as $renewCredit) {
            $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();

            $pendingAmount = 0;
            if ($oldCredit) {
                $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                $oldCreditPaid = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                $total_pending_absorbed += $pendingAmount;
            }

            $netDisbursement = $renewCredit->credit_value - $pendingAmount;
            $total_renewal_disbursed += $netDisbursement;

            \Log::debug("Renovación - ID: {$renewCredit->id}, Valor: {$renewCredit->credit_value}, Pendiente absorbido: $pendingAmount, Neto desembolsado: $netDisbursement");
        }

        /*   \Log::debug("Total pending absorbed: $total_pending_absorbed");
        \Log::debug("Total renewal disbursed: $total_renewal_disbursed"); */

        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        \Log::debug("Créditos irrecuperables: $irrecoverableCredits");

        // Cálculo del realToDeliver
        $realToDeliver = $liquidation->initial_cash
            + $liquidation->base_delivered
            + ($totalIncome + $totalCollected)
            - ($totalExpenses
                + $newCredits
                + $total_renewal_disbursed
                + $irrecoverableCredits);
        /* 
        \Log::debug("Cálculo realToDeliver:");
        \Log::debug("initial_cash ({$liquidation->initial_cash}) + base_delivered ({$liquidation->base_delivered}) + (totalIncome ($totalIncome) + totalCollected ($totalCollected)) - (totalExpenses ($totalExpenses) + newCredits ($newCredits) + total_renewal_disbursed ($total_renewal_disbursed) + irrecoverableCredits ($irrecoverableCredits)) = $realToDeliver");
 */
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

        /*  \Log::debug("cashDelivered: $cashDelivered, shortage: $shortage, surplus: $surplus");
 */
        // Verificar si hay cambios
        $hasChanges = !(
            $liquidation->total_expenses == $totalExpenses &&
            $liquidation->new_credits == $newCredits &&
            $liquidation->total_income == $totalIncome &&
            $liquidation->total_collected == $totalCollected &&
            $liquidation->real_to_deliver == $realToDeliver &&
            $liquidation->shortage == $shortage &&
            $liquidation->surplus == $surplus &&
            $liquidation->renewal_disbursed_total == $total_renewal_disbursed &&
            $liquidation->total_pending_absorbed == $total_pending_absorbed
        );

        if (!$hasChanges) {
            /*  \Log::debug("✅ NO hay cambios en los datos - No se actualiza la liquidación");
            \Log::debug("=== FIN recalculateLiquidation (sin cambios) ==="); */
            return; // No hay cambios, no actualizar
        }
        /* 
        \Log::debug("🔄 HAY CAMBIOS - Actualizando liquidación:");
        \Log::debug("Antes -> Después:");
        \Log::debug("total_expenses: {$liquidation->total_expenses} -> $totalExpenses");
        \Log::debug("new_credits: {$liquidation->new_credits} -> $newCredits");
        \Log::debug("total_income: {$liquidation->total_income} -> $totalIncome");
        \Log::debug("total_collected: {$liquidation->total_collected} -> $totalCollected");
        \Log::debug("real_to_deliver: {$liquidation->real_to_deliver} -> $realToDeliver");
        \Log::debug("shortage: {$liquidation->shortage} -> $shortage");
        \Log::debug("surplus: {$liquidation->surplus} -> $surplus");
        \Log::debug("total_renewal_disbursed: {$liquidation->total_renewal_disbursed} -> $total_renewal_disbursed");
        \Log::debug("total_crossed_credits: {$liquidation->total_crossed_credits} -> $total_pending_absorbed");
 */
        $liquidation->update([
            'total_expenses'           => $totalExpenses,
            'new_credits'              => $newCredits,
            'total_income'             => $totalIncome,
            'total_collected'          => $totalCollected,
            'real_to_deliver'          => $realToDeliver,
            'shortage'                 => $shortage,
            'surplus'                  => $surplus,
            'renewal_disbursed_total'  => $total_renewal_disbursed,
            'irrecoverable_credits_amount' => $irrecoverableCredits,
            'total_pending_absorbed'    => $total_pending_absorbed,
        ]);

        /*   \Log::debug("✅ Liquidación actualizada exitosamente");
        \Log::debug("=== FIN recalculateLiquidation (con actualización) ==="); */
    }

    protected function getDailyTotals($sellerId, $date, $userId, $timezone = null)
    {
        $tz = $timezone ?: self::TIMEZONE;
        $startUTC = Carbon::parse($date, $tz)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $tz)->endOfDay()->setTimezone('UTC');

        $query = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereBetween('payments.created_at', [$startUTC, $endUTC])
            ->where('credits.seller_id', $sellerId)
            ->groupBy('payments.payment_method');

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(DB::raw('MIN(payments.created_at) as first_payment_date'))
            ->whereBetween('payments.created_at', [$startUTC, $endUTC]);

        if ($sellerId) {
            $firstPaymentQuery->where('credits.seller_id', $sellerId);
        }

        $firstPaymentResult = $firstPaymentQuery->first();
        $firstPaymentDate = $firstPaymentResult->first_payment_date;

        $paymentResults = $query->get();

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0,
            'base_value' => 0,
            'liquidation_start_date' => $firstPaymentDate
        ];

        foreach ($paymentResults as $result) {
            $amount = (float)$result->total;
            if ($result->payment_method === 'Efectivo') {
                $totals['cash'] = $amount;
            } elseif ($result->payment_method === 'Transferencia') {
                $totals['transfer'] = $amount;
            }
            $totals['collected_total'] += $amount;
        }

        // Obtener total esperado
        $totals['expected_total'] = (float)DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->whereBetween('installments.due_date', [$startUTC, $endUTC])
            ->sum('installments.quota_amount');

        // Obtener créditos creados
        $credits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
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

        $totals['created_credits_value'] = (float)$credits->value;
        $totals['created_credits_interest'] = (float)$credits->interest;

        // Obtener gastos
        $totals['total_expenses'] = (float)Expense::where('user_id', $userId)
            ->whereBetween('updated_at', [$startUTC, $endUTC])
            ->where('status', 'Aprobado')
            ->sum('value');

        $totals['total_income'] = (float)Income::where('user_id', $userId)
            ->whereBetween('updated_at', [$startUTC, $endUTC])
            ->sum('value');

        // Obtener total clientes
        $totals['total_clients'] = (int)DB::table('clients')
            ->whereExists(function ($query) use ($sellerId) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->where('credits.seller_id', $sellerId);
            })
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
                'NuevoCreditoID'     => $renewCredit->id,
                'MontoTotalNuevo_Y'  => $renewCredit->credit_value,
                'SaldoPendienteAbsorbido' => $pendingAmount,
                'DesembolsoNeto'     => $netDisbursement,
                'ClienteID'          => $renewCredit->client_id,
                'CreditoAnteriorID'  => $renewCredit->renewed_from_id,
            ];
        }

        $totals['total_renewal_disbursed'] = $total_renewal_disbursed;
        $totals['total_crossed_credits'] = $total_pending_absorbed;;
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
            'liquidation_start_date' => $firstPaymentDate,
            'total_pending_absorbed' => $liquidation->total_pending_absorbed,
            'total_crossed_credits' => $dailyTotals['total_crossed_credits'],
            'total_renewal_disbursed' => $dailyTotals['total_renewal_disbursed'],
            'audits' => $liquidation->audits->filter(function ($audit) {
                return in_array(optional($audit->user)->role_id, [5]);
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

    public function getReportByCity($startDate, $endDate)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');

        $cities = DB::table('cities')->get();
        $report = [];

        foreach ($cities as $city) {
            $liquidations = Liquidation::whereHas('seller', function ($q) use ($city) {
                $q->where('city_id', $city->id);
            })
                ->whereBetween('date', [$startUTC, $endUTC])
                ->get();

            if ($liquidations->count() > 0) {
                $previous_cash = Liquidation::whereHas('seller', function ($q) use ($city) {
                    $q->where('city_id', $city->id);
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
                    ->whereHas('user', function ($q) use ($city) {
                        $q->where('city_id', $city->id);
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
    public function getAccumulatedByCity($startDate, $endDate)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');

        \Log::debug("getAccumulatedByCity - Rango UTC:", ['startUTC' => $startUTC, 'endUTC' => $endUTC]);

        $query = DB::table('liquidations')
            ->join('sellers', 'liquidations.seller_id', '=', 'sellers.id')
            ->join('cities', 'sellers.city_id', '=', 'cities.id')
            ->select(
                'cities.name as city_name',
                'cities.id as city_id',
                DB::raw('SUM(liquidations.total_collected) as total_collected'),
                DB::raw('SUM(liquidations.total_expenses) as total_expenses'),
                DB::raw('SUM(liquidations.new_credits) as new_credits'),
                DB::raw('SUM(liquidations.initial_cash) as initial_cash'),
                DB::raw('SUM(liquidations.base_delivered) as base_delivered'),
                DB::raw('SUM(liquidations.real_to_deliver) as real_to_deliver'),
                DB::raw('SUM(liquidations.shortage) as shortage'),
                DB::raw('SUM(liquidations.surplus) as surplus'),
                DB::raw('SUM(liquidations.cash_delivered) as cash_delivered')
            )
            ->whereBetween('liquidations.date', [$startUTC, $endUTC])
            ->where('liquidations.status', 'approved')
            ->groupBy('cities.id', 'cities.name');

        \Log::debug("getAccumulatedByCity - SQL:", ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);

        $result = $query->get();
        \Log::debug("getAccumulatedByCity - Resultado:", ['count' => $result->count(), 'data' => $result]);
        return $result;
    }

    public function getAccumulatedBySellerInCity($cityId, $startDate, $endDate)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');

        return DB::table('liquidations')
            ->join('sellers', 'liquidations.seller_id', '=', 'sellers.id')
            ->join('cities', 'sellers.city_id', '=', 'cities.id')
            ->select(
                'sellers.id as seller_id',
                'sellers.seller_id as seller_code',
                'users.name as seller_name',
                DB::raw('SUM(liquidations.total_collected) as total_collected'),
                DB::raw('SUM(liquidations.total_expenses) as total_expenses'),
                DB::raw('SUM(liquidations.new_credits) as new_credits'),
                DB::raw('SUM(liquidations.initial_cash) as initial_cash'),
                DB::raw('SUM(liquidations.base_delivered) as base_delivered'),
                DB::raw('SUM(liquidations.real_to_deliver) as real_to_deliver'),
                DB::raw('SUM(liquidations.shortage) as shortage'),
                DB::raw('SUM(liquidations.surplus) as surplus'),
                DB::raw('SUM(liquidations.cash_delivered) as cash_delivered')
            )
            ->join('users', 'sellers.user_id', '=', 'users.id')
            ->where('cities.id', $cityId)
            ->whereBetween('liquidations.date', [$startUTC, $endUTC])
            ->groupBy('sellers.id', 'sellers.seller_id', 'users.name')
            ->get();
    }

    public function getAccumulatedBySellersInCity($cityId, $startDate, $endDate)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');

        return DB::table('liquidations')
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
                DB::raw('SUM(liquidations.initial_cash) as initial_cash'),
                DB::raw('SUM(liquidations.base_delivered) as base_delivered'),
                DB::raw('SUM(liquidations.real_to_deliver) as real_to_deliver'),
                DB::raw('SUM(liquidations.shortage) as shortage'),
                DB::raw('SUM(liquidations.surplus) as surplus'),
                DB::raw('SUM(liquidations.cash_delivered) as cash_delivered'),
                DB::raw('COUNT(liquidations.id) as liquidation_count')
            )
            ->where('cities.id', $cityId)
            ->where('liquidations.status', 'approved')
            ->whereBetween('liquidations.date', [$startUTC, $endUTC])
            ->groupBy('sellers.id', 'users.name', 'cities.name')
            ->get();
    }

    public function getSellerLiquidationsDetail($sellerId, $startDate, $endDate)
    {
        $timezone = 'America/Lima';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');

        return Liquidation::with(['seller', 'seller.user'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->orderBy('date', 'asc')
            ->get();
    }

    public function reopenRoute($sellerId, $date)
    {
        $timezone = 'America/Lima';
        $dateLocal = \Carbon\Carbon::parse($date, $timezone)->format('Y-m-d');
        $startUTC = \Carbon\Carbon::parse($dateLocal, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = \Carbon\Carbon::parse($dateLocal, $timezone)->endOfDay()->setTimezone('UTC');

        $liquidation = \App\Models\Liquidation::where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->first();

        if (!$liquidation) {
            return ['message' => 'No existe liquidación para ese vendedor y fecha', 'audits_deleted' => 0];
        }

        $seller = \App\Models\Seller::find($liquidation->seller_id);
        $userId = $seller ? $seller->user_id : null;

        $deleted = \App\Models\LiquidationAudit::where('liquidation_id', $liquidation->id)
            ->where('user_id', $userId)
            ->whereIn('action', ['updated', 'created'])
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->delete();

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
            ->whereHas('payments', function ($query) use ($start, $end) {
                $query->whereBetween('payments.created_at', [$start, $end]);
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
            $quotaAmount = ($credit->credit_value + $interestAmount + $credit->micro_insurance_amount) / $credit->number_installments;
            $totalCreditValue = $credit->credit_value + $interestAmount + $credit->micro_insurance_amount;
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

            $totalCreditAmount = $credit->credit_value + $interestAmount + $credit->micro_insurance_amount;
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
    public function getFirstApprovedLiquidationBySeller()
    {
        $sellers = Seller::with(['user', 'city.country'])->get();
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

        // Créditos nuevos (de esta liquidación)
        $creditosNuevosQuery = \App\Models\Credit::where('seller_id', $liquidation->seller_id)
            ->whereNull('renewed_from_id')
            ->whereNull('renewed_to_id')
            ->whereNull('unification_reason')
            ->whereBetween('created_at', [
                Carbon::parse($liquidation->date, self::TIMEZONE)->startOfDay()->setTimezone('UTC'),
                Carbon::parse($liquidation->date, self::TIMEZONE)->endOfDay()->setTimezone('UTC')
            ]);
        $creditosNuevosPaginados = $creditosNuevosQuery->paginate($perPage, ['*'], 'creditos_page', $page);

        // Calcular la suma de la póliza de los créditos nuevos
        $polizaTotal = (clone $creditosNuevosQuery)->get()->sum(function($c) {
            return ($c->micro_insurance_percentage * $c->credit_value) / 100;
        });

        // Pagos (cobrados en esta liquidación)
        $pagosQuery = \App\Models\Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $liquidation->seller_id)
            ->whereBetween('payments.created_at', [
                Carbon::parse($liquidation->date, self::TIMEZONE)->startOfDay()->setTimezone('UTC'),
                Carbon::parse($liquidation->date, self::TIMEZONE)->endOfDay()->setTimezone('UTC')
            ])
            ->select('payments.*');
        $pagosPaginados = $pagosQuery->paginate($perPage, ['*'], 'pagos_page', $page);

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
                'creditos_nuevos' => $creditosNuevos,
                'creditos_nuevos_count' => $creditosNuevosCount,
                'base_entregada' => $baseEntregada,
                'collection_target' => $liquidation->collection_target,
                'initial_cash' => $liquidation->initial_cash,
                'total_collected' => $liquidation->total_collected,
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
}
