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

    public function getLiquidationData($sellerId, $date, $userId)
    {
        $timezone = 'America/Caracas';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');
    
        // 1. Verificar si ya existe liquidación para esta fecha
        $existingLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->first();
    
        // Si existe liquidación, retornar directamente esos datos
        if ($existingLiquidation) {
            // Recalcula los datos de la liquidación antes de devolverlos
            $this->recalculateLiquidation($sellerId, $date);
    
            // Vuelve a obtener la liquidación actualizada
            $updatedLiquidation = Liquidation::with('audits')->where('seller_id', $sellerId)
                ->whereBetween('date', [$startUTC, $endUTC])
                ->first();
    
            return $this->formatLiquidationResponse($updatedLiquidation, true);
        }
        // 2. Obtener datos del endpoint dailyPaymentTotals
        $dailyTotals = $this->getDailyTotals($sellerId, $date, $userId);
    
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
        $timezone = 'America/Caracas';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        // Busca la liquidación del vendedor en esa fecha
        $liquidation = Liquidation::where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->first();

        if (!$liquidation) return;

        // 1. Obtener el user_id del vendedor
        $seller = Seller::find($sellerId);
        $userId = $seller ? $seller->user_id : null;

        // 2. Recalcula los totales actuales desde la BD
        $totalExpenses = $userId
            ? Expense::where('user_id', $userId)
            ->whereBetween('created_at', [$startUTC, $endUTC])->sum('value')
            : 0;

        $totalIncome = $userId
            ? Income::where('user_id', $userId)
            ->whereBetween('created_at', [$startUTC, $endUTC])->sum('value')
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

        // === Detalle de renovaciones ===
        $renewalCredits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$startUTC, $endUTC])
            ->whereNotNull('renewed_from_id')
            ->get();




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
        }

        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        $realToDeliver = $liquidation->initial_cash
            + $liquidation->base_delivered
            + ($totalIncome + $totalCollected)
            - ($totalExpenses
                + $newCredits
                + $total_renewal_disbursed
                + $irrecoverableCredits);

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


        // Solo actualiza si hubo cambios en los totales
        if (
            $liquidation->total_expenses == $totalExpenses &&
            $liquidation->new_credits == $newCredits &&
            $liquidation->total_income == $totalIncome &&
            $liquidation->total_collected == $totalCollected &&
            $liquidation->real_to_deliver == $realToDeliver &&
            $liquidation->shortage == $shortage &&
            $liquidation->surplus == $surplus &&
            $liquidation->total_renewal_disbursed == $total_renewal_disbursed &&
            $liquidation->total_crossed_credits == $total_pending_absorbed
        ) {
            return; // No hay cambios, no actualizar
        }

        $liquidation->update([
            'total_expenses'           => $totalExpenses,
            'new_credits'              => $newCredits,
            'total_income'             => $totalIncome,
            'total_collected'          => $totalCollected,
            'real_to_deliver'          => $realToDeliver,
            'shortage'                 => $shortage,
            'surplus'                  => $surplus,
            'total_renewal_disbursed'  => $total_renewal_disbursed,
            'total_crossed_credits'    => $total_pending_absorbed,
        ]);
    }

    protected function getDailyTotals($sellerId, $date, $userId)
    {

        $timezone = 'America/Caracas';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

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
            'total_crossed_credits' => $dailyTotals['total_crossed_credits'],
            'total_renewal_disbursed' => $dailyTotals['total_renewal_disbursed'],
            'audits' => $liquidation->audits,

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
            'created_at' => $liquidation->created_at
        ];
    }
    protected function getPreviousLiquidation($sellerId, $date)
    {
        $timezone = 'America/Caracas';
        $startUTC = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
    
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $startUTC)
            ->orderBy('date', 'desc')
            ->first();
    
        return $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null;
    }

    public function getReportByCity($startDate, $endDate)
    {
        $timezone = 'America/Caracas';
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
        $timezone = 'America/Caracas';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');
    
        return DB::table('liquidations')
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
            ->groupBy('cities.id', 'cities.name')
            ->get();
    }
    
    public function getAccumulatedBySellerInCity($cityId, $startDate, $endDate)
    {
        $timezone = 'America/Caracas';
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
        $timezone = 'America/Caracas';
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
        $timezone = 'America/Caracas';
        $startUTC = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $endUTC   = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone('UTC');
    
        return Liquidation::with(['seller', 'seller.user'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$startUTC, $endUTC])
            ->orderBy('date', 'asc')
            ->get();
    }
}
