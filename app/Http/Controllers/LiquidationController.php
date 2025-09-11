<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Income;
use App\Services\LiquidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Liquidation;
use App\Models\Expense;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LiquidationController extends Controller
{
    protected $liquidationService;
    public function __construct(LiquidationService $liquidationService)
    {
        $this->liquidationService = $liquidationService;
    }
    public function calculateLiquidation(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id'
        ]);

        $user = Auth::user();
        $sellerId = $request->seller_id;
        $date = $request->date;

        // Verificar permisos
        if (!$this->checkAuthorization($user, $sellerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Obtener datos para la liquidación
        $data = $this->getLiquidationData($sellerId, $date);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function storeLiquidation(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id',
            'cash_delivered' => 'required|numeric|min:0',
            'path' => 'nullable|image|max:2048',
            'initial_cash' => 'required|numeric',
            'base_delivered' => 'required|numeric|min:0',
            'total_collected' => 'required|numeric|min:0',
            'total_expenses' => 'required|numeric|min:0',
            'new_credits' => 'required|numeric|min:0',
            'created_at' => 'nullable|date',
        ]);

        // Verificar si ya existe liquidación para este día
        $existingLiquidation = Liquidation::where('seller_id', $request->seller_id)
            ->whereDate('date', $request->date)
            ->first();

        if ($existingLiquidation) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una liquidación para este vendedor en la fecha seleccionada'
            ], 422);
        }

        $pendingExpenses = Expense::where('user_id', $user->id)
            ->whereDate('created_at', $request->date)
            ->where('status', 'Pendiente')
            ->exists();

        if ($pendingExpenses) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede liquidar porque tienes gastos pendientes de aprobación en la fecha seleccionada'
            ], 422);
        }

        $realToDeliver = $request->initial_cash + ($request->total_income + $request->total_collected)
            - $request->total_expenses - $request->new_credits;

        $shortage = 0;
        $surplus = 0;
        $pendingDebt = 0;

        if ($realToDeliver > 0) {
            if ($request->cash_delivered < $realToDeliver) {
                $shortage = $realToDeliver - $request->cash_delivered;
            } else {
                $surplus = $request->cash_delivered - $realToDeliver;
            }
        } else {
            $debtAmount = abs($realToDeliver);

            if ($request->cash_delivered > $debtAmount) {
                $surplus = $request->cash_delivered - $debtAmount;
                $pendingDebt = 0;
            } else {
                $pendingDebt = $debtAmount - $request->cash_delivered;
                $shortage = $pendingDebt;
            }
        }



        $liquidationData = [
            'date' => $request->date,
            'seller_id' => $request->seller_id,
            'collection_target' => $request->collection_target,
            'initial_cash' => $request->initial_cash,
            'base_delivered' => $request->base_delivered,
            'total_collected' => $request->total_collected,
            'total_expenses' => $request->total_expenses,
            'new_credits' => $request->new_credits,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'path' => $request->path,
            'cash_delivered' => $request->cash_delivered,
            'status' => 'pending'
        ];

        if ($request->has('created_at')) {
            $liquidationData['created_at'] = $request->created_at;
        }

        if ($request->has('path')) {
            $imageFile = $request->file('path');
            $imagePath = Helper::uploadFile($imageFile, 'liquidations');
            $liquidationData['path'] = $imagePath;
        }


        $liquidation = Liquidation::create($liquidationData);

        if ($user->role_id === 5) {
            $seller = Seller::find($request->seller_id);

            $adminUsers = User::whereIn('role_id', [1, 2])->get();

            foreach ($adminUsers as $adminUser) {
                $adminUser->notify(new GeneralNotification(
                    'Solicitud de liquidación ',
                    'El vendedor ' . $seller->user->name . ' de la ruta ' . $seller->city->country->name . ',' .  $seller->city->name . ' ha creado una nueva liquidación para la fecha ' . $request->date,
                    '/dashboard/liquidaciones'
                ));
            }
        }

        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidación guardada correctamente'
        ]);
    }

    public function updateLiquidation(Request $request, $id)
    {
        $user = Auth::user();

        $request->validate([
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id',
            'cash_delivered' => 'required|numeric|min:0',
            'initial_cash' => 'required|numeric',
            'base_delivered' => 'required|numeric|min:0',
            'total_collected' => 'required|numeric|min:0',
            'total_expenses' => 'required|numeric|min:0',
            'new_credits' => 'required|numeric|min:0',
            'total_income' => 'required|numeric|min:0',
            'created_at' => 'nullable|date',
        ]);

        $liquidation = Liquidation::findOrFail($id);

        $existingLiquidation = Liquidation::where('seller_id', $request->seller_id)
            ->whereDate('date', $request->date)
            ->where('id', '!=', $id)
            ->first();

        if ($existingLiquidation && $user->role_id != 1 && $user->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe otra liquidación para este vendedor en la fecha seleccionada'
            ], 422);
        }

        $pendingExpenses = Expense::where('user_id', $user->id)
            ->whereDate('created_at', $request->date)
            ->where('status', 'Pendiente')
            ->exists();

        if ($pendingExpenses) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede liquidar porque tienes gastos pendientes de aprobación en la fecha seleccionada'
            ], 422);
        }

        $realToDeliver = $request->initial_cash +
            ($request->total_income + $request->total_collected) -
            $request->total_expenses -
            $request->new_credits;

        $shortage = 0;
        $surplus = 0;

        if ($realToDeliver > 0) {
            if ($request->cash_delivered < $realToDeliver) {
                $shortage = $realToDeliver - $request->cash_delivered;
            } else {
                $surplus = $request->cash_delivered - $realToDeliver;
            }
        } else {
            $debtAmount = abs($realToDeliver);
            if ($request->cash_delivered > $debtAmount) {
                $surplus = $request->cash_delivered - $debtAmount;
            } else {
                $shortage = $debtAmount - $request->cash_delivered;
            }
        }

        $liquidation->update([
            'date' => $request->date,
            'seller_id' => $request->seller_id,
            'collection_target' => $request->collection_target,
            'initial_cash' => $request->initial_cash,
            'base_delivered' => $request->base_delivered,
            'total_collected' => $request->total_collected,
            'total_expenses' => $request->total_expenses,
            'new_credits' => $request->new_credits,
            'real_to_deliver' => $realToDeliver,
            'shortage' => $shortage,
            'surplus' => $surplus,
            'cash_delivered' => $request->cash_delivered,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidación cerrada correctamente'
        ]);
    }

    public function approveLiquidation($id)
    {
        $user = Auth::user();

        $liquidation = Liquidation::findOrFail($id);

        if ($user->role_id != 1 && $user->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para aprobar liquidaciones'
            ], 403);
        }

        if ($liquidation->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'La liquidación ya ha sido aprobada'
            ], 422);
        }

        $liquidation->update([
            'status' => 'approved',
        ]);

        return response()->json([
            'success' => true,
            'data' => $liquidation,
            'message' => 'Liquidación cerrada correctamente'
        ]);
    }

    public function annulBase(Request $request, $id)
    {

        $liquidation = Liquidation::findOrFail($id);

        try {
            DB::beginTransaction();

            $liquidation->update([
                'base_delivered' => 0,

            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $liquidation,
                'message' => 'Base anulada correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al anular la base: ' . $e->getMessage()
            ], 500);
        }
    }

    private function checkAuthorization($user, $sellerId)
    {
        // Admins y supervisores pueden acceder a cualquier vendedor
        if (in_array($user->role_id, [1, 2])) {
            return true;
        }

        // Vendedores solo pueden acceder a sus propios datos
        if ($user->role_id == 5) {
            $seller = Seller::where('user_id', $user->id)->first();
            return $seller && $seller->id == $sellerId;
        }

        return false;
    }

    public function getLiquidationData($sellerId, $date)
    {
        $user = Auth::user();

        // 1. Verificar si ya existe liquidación para esta fecha
        $existingLiquidation = Liquidation::where('seller_id', $sellerId)
            ->whereDate('date', $date)
            ->first();

        // Si existe liquidación, retornar directamente esos datos
        if ($existingLiquidation) {
            return $this->formatLiquidationResponse($existingLiquidation, true);
        }

        // 2. Obtener datos del endpoint dailyPaymentTotals
        $dailyTotals = $this->getDailyTotals($sellerId, $date, $user);


        // 3. Obtener última liquidación para saldo inicial
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

        Log::info('INcome: ' . $dailyTotals['total_income']);
        Log::info('Initial Cash: ' . $initialCash);
        // 4. Calcular valor real a entregar
        $realToDeliver = $initialCash
            + ($dailyTotals['total_income'] + $dailyTotals['collected_total'])
            - ($dailyTotals['created_credits_value']
                - $dailyTotals['total_expenses']);

        // 5. Estructurar respuesta completa
        return [
            'collection_target' => $dailyTotals['daily_goal'],
            'initial_cash' => $initialCash,
            'base_delivered' => $dailyTotals['base_value'],
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
            'liquidation_start_date' => $dailyTotals['liquidation_start_date']
        ];
    }

    // Nuevo método para obtener los dailyTotals
    protected function getDailyTotals($sellerId, $date, $user)
    {
        $formattedDate = Carbon::parse($date)->format('Y-m-d');
        $targetDate = Carbon::parse($date);
        $query = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereDate('payments.payment_date', $date)
            ->where('credits.seller_id', $sellerId)
            ->where('payments.status', 'Aprobado')
            ->groupBy('payments.payment_method');

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(DB::raw('MIN(payments.created_at) as first_payment_date'))
            ->whereDate('payments.payment_date', $date);

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
            ->where('installments.due_date', $date)
            ->sum('installments.quota_amount');

        // Obtener créditos creados
        $credits = DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [
                $targetDate->startOfDay()->format('Y-m-d H:i:s'),
                $targetDate->endOfDay()->format('Y-m-d H:i:s')
            ])
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

        Log::info('Créditos creados en ' . $targetDate->format('Y-m-d') . ':', [
            'query' => DB::table('credits')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [
                    $targetDate->startOfDay()->format('Y-m-d H:i:s'),
                    $targetDate->endOfDay()->format('Y-m-d H:i:s')
                ])
                ->toSql(),
            'bindings' => DB::table('credits')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [
                    $targetDate->startOfDay()->format('Y-m-d H:i:s'),
                    $targetDate->endOfDay()->format('Y-m-d H:i:s')
                ])
                ->getBindings(),
            'result' => $credits
        ]);


        $totals['created_credits_value'] = (float)$credits->value;
        $totals['created_credits_interest'] = (float)$credits->interest;

        // Obtener gastos
        $totals['total_expenses'] = (float)Expense::where('user_id', $user->id)
            ->whereDate('updated_at', $date)
            ->where('status', 'Aprobado')
            ->sum('value');

        $totals['total_income'] = (float)Income::where('user_id', $user->id)
            ->whereDate('updated_at', $date)
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

        Log::info($totals['expected_total']);

        return $totals;
    }

    public function getBySeller(Request $request, $sellerId)
    {
        try {
            $perPage = $request->input('per_page', 20);


            $response = $this->liquidationService->getLiquidationsBySeller(
                $sellerId,
                $request,
                $perPage
            );

            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener liquidaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene estadísticas de un vendedor
     *
     * @param int $sellerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSellerStats($sellerId)
    {
        try {
            $response = $this->liquidationService->getSellerStats($sellerId);
            return $response;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Muestra una liquidación específica
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $liquidation = Liquidation::with('seller')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $liquidation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Liquidación no encontrada'
            ], 404);
        }
    }

    // Método para formatear respuesta de liquidación existente
    protected function formatLiquidationResponse($liquidation, $isExisting = false)
    {
        $firstPaymentDate = null;
        if ($isExisting) {
            $firstPaymentQuery = DB::table('payments')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->select('payments.payment_date', 'payments.created_at') // 👈 aquí
                ->whereDate('payments.payment_date', $liquidation->date)
                ->where('credits.seller_id', $liquidation->seller_id)
                ->orderBy('payments.payment_date', 'asc')
                ->first();


            if ($firstPaymentQuery) {
                $firstPaymentDate = $firstPaymentQuery->created_at;
            }
        }
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
            'existing_liquidation' => $isExisting ? $this->formatLiquidationDetails($liquidation) : null,
            'last_liquidation' => $this->getPreviousLiquidation($liquidation->seller_id, $liquidation->date),
            'is_new' => false, // Indicador de que ya existe
            'liquidation_start_date' => $firstPaymentDate

        ];
    }

    // Método para obtener liquidación anterior
    protected function getPreviousLiquidation($sellerId, $date)
    {
        $lastLiquidation = Liquidation::where('seller_id', $sellerId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        return $lastLiquidation ? $this->formatLiquidationDetails($lastLiquidation) : null;
    }

    // Método para formatear detalles de liquidación
    protected function formatLiquidationDetails($liquidation)
    {
        return [
            'id' => $liquidation->id,
            'date' => $liquidation->date,
            'real_to_deliver' => $liquidation->real_to_deliver,
            'total_collected' => $liquidation->total_collected,
            'total_expenses' => $liquidation->total_expenses,
            'new_credits' => $liquidation->new_credits,
            'base_delivered' => $liquidation->base_delivered,
            'shortage' => $liquidation->shortage,
            'surplus' => $liquidation->surplus,
            'cash_delivered' => $liquidation->cash_delivered,
            'status' => $liquidation->status,
            'created_at' => $liquidation->created_at
        ];
    }
    public function getLiquidationHistory(Request $request)
    {
        $request->validate([
            'seller_id' => 'required|exists:sellers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        $user = Auth::user();
        $sellerId = $request->seller_id;


        // Verificar permisos
        if (!$this->checkAuthorization($user, $sellerId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $history = Liquidation::with(['expenses', 'credits'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }
    public function getAccumulatedByCity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $results = $this->liquidationService->getAccumulatedByCity($startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Datos obtenidos exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAccumulatedByCityWithSellers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city_id' => 'required|exists:cities,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = $request->input('city_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Llamar al nuevo servicio
            $results = $this->liquidationService->getAccumulatedBySellerInCity($cityId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Datos obtenidos exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getSellersSummaryByCity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = $request->input('city_id');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $results = $this->liquidationService->getAccumulatedBySellersInCity($cityId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Resumen por vendedores obtenido exitosamente',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen por vendedores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSellerLiquidationsDetail(Request $request, $sellerId)
{
    $validator = Validator::make($request->all(), [
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validación fallida',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $liquidations = Liquidation::with(['seller', 'seller.user'])
            ->where('seller_id', $sellerId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Liquidaciones del vendedor obtenidas exitosamente',
            'data' => $liquidations
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener las liquidaciones del vendedor',
            'error' => $e->getMessage()
        ], 500);
    }
}
}
