<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PaymentService;
use App\Http\Requests\Payment\PaymentRequest;
use App\Models\Liquidation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
        // Supervisor (rol 6) en modo solo-lectura cuando supervisa un
        // vendedor con caja cerrada. El middleware filtra por rol y por
        // active_seller_id; otros roles pasan transparentes.
        $this->middleware('block.writes.cash.closed')->only([
            'create',
            'delete',
            'deletePaymentInstallment',
            'reapply',
        ]);
    }

    public function create(PaymentRequest $request)
    {
        try {
            return $this->paymentService->create($request);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request, $creditId)
    {
        try {
            \App\Support\Tenant::assertCreditInScope($creditId);
            $perPage = $request->get('perPage') ?? $request->get('rowsPerPage') ?? 100;

            return $this->paymentService->index($creditId, $request, $perPage);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e; // dejar pasar 401/403/404 de autorización
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }


    public function paymentsToday(Request $request, $creditId)
    {
        try {
            $perPage = $request->get('perPage') ?? $request->get('rowsPerPage') ?? 100;

            return $this->paymentService->paymentsToday($creditId, $request, $perPage);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }


    public function indexBySeller(Request $request, $sellerId)
    {
        try {
            // Resolve UUID to ID if necessary
            if (!is_numeric($sellerId)) {
                $seller = \App\Models\Seller::where('uuid', $sellerId)->firstOrFail();
                $sellerId = $seller->id;
            }

            $perPage = $request->get('perPage') ?? $request->get('rowsPerPage') ?? 10;

            return $this->paymentService->getPaymentsBySeller($sellerId, $request, $perPage);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getSellerPayments(Request $request, $sellerId)
    {
        try {
            // Resolve UUID to ID if necessary
            if (!is_numeric($sellerId)) {
                $seller = \App\Models\Seller::where('uuid', $sellerId)->firstOrFail();
                $sellerId = $seller->id;
            }

            return $this->paymentService->getAllPaymentsBySeller($sellerId, $request);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener los créditos del vendedor: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Version ligera: totales del dia + lista de pagos del vendedor en una unica query.
     * Reemplaza las 2 llamadas (daily-totals + for-collections) que el modal hacia antes.
     */
    public function getSellerDelDiaLite(Request $request, $sellerId)
    {
        try {
            if (!is_numeric($sellerId)) {
                $seller = \App\Models\Seller::where('uuid', $sellerId)->firstOrFail();
                $sellerId = $seller->id;
            }

            $date = $request->get('date') ?: \Carbon\Carbon::now($request->get('timezone', 'America/Lima'))->toDateString();

            $rows = \DB::table('payments')
                ->join('credits', 'credits.id', '=', 'payments.credit_id')
                ->join('clients', 'clients.id', '=', 'credits.client_id')
                ->where('credits.seller_id', $sellerId)
                ->whereNull('payments.deleted_at')
                ->whereNull('credits.deleted_at')
                ->whereNull('clients.deleted_at')
                ->whereRaw("COALESCE(payments.business_date, DATE(payments.created_at)) = ?", [$date])
                ->select(
                    'payments.id',
                    'payments.amount',
                    'payments.payment_method',
                    'payments.status as payment_status',
                    'credits.id as credit_id',
                    'clients.id as client_id',
                    'clients.name as client_name'
                )
                ->orderBy('payments.created_at', 'asc')
                ->get();

            // Agrupar por credit_id (1 fila por credito con monto sumado)
            $items = $rows->groupBy('credit_id')->map(function ($group) {
                $first = $group->first();
                return [
                    'credit_id' => $first->credit_id,
                    'client_id' => $first->client_id,
                    'cliente'   => $first->client_name,
                    'payment_method' => $first->payment_method,
                    'monto'     => (float) $group->sum('amount'),
                ];
            })->values();

            $cash     = (float) $rows->where('payment_method', 'Efectivo')->sum('amount');
            $transfer = (float) $rows->where('payment_method', 'Transferencia')->sum('amount');

            return response()->json([
                'success' => true,
                'date'    => $date,
                'totals'  => [
                    'collected_total' => (float) $rows->sum('amount'),
                    'cash'            => $cash,
                    'transfer'        => $transfer,
                    'clients_count'   => $rows->pluck('client_id')->unique()->count(),
                    'payments_count'  => $rows->count(),
                ],
                'items' => $items,
            ]);
        } catch (\Exception $e) {
            \Log::error("getSellerDelDiaLite error: " . $e->getMessage());
            return $this->errorResponse('Error al obtener pagos del dia del vendedor', 500);
        }
    }

    /**
     * Version ligera para el modal del dashboard: devuelve todos los pagos del
     * vendedor con columnas minimas y sin eager loading pesado.
     * Soporta filtros opcionales start_date / end_date (por business_date).
     */
    public function getSellerCobradoLite(Request $request, $sellerId)
    {
        try {
            if (!is_numeric($sellerId)) {
                $seller = \App\Models\Seller::where('uuid', $sellerId)->firstOrFail();
                $sellerId = $seller->id;
            }

            $query = \DB::table('payments')
                ->join('credits', 'credits.id', '=', 'payments.credit_id')
                ->join('clients', 'clients.id', '=', 'credits.client_id')
                ->where('credits.seller_id', $sellerId)
                ->whereNull('payments.deleted_at')
                ->whereNull('credits.deleted_at')
                ->whereNull('clients.deleted_at');

            if ($request->filled('start_date')) {
                $query->where(\DB::raw("COALESCE(payments.business_date, DATE(payments.created_at))"), '>=', $request->get('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->where(\DB::raw("COALESCE(payments.business_date, DATE(payments.created_at))"), '<=', $request->get('end_date'));
            }

            $rows = $query
                ->select(
                    'payments.id',
                    'payments.amount',
                    'payments.payment_method',
                    'payments.status as payment_status',
                    'payments.business_date',
                    'payments.payment_date',
                    'payments.created_at',
                    'credits.id as credit_id',
                    'clients.id as client_id',
                    'clients.name as client_name'
                )
                ->orderBy('payments.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $rows,
                'total' => $rows->count(),
                'total_amount' => (float) $rows->sum('amount'),
            ]);
        } catch (\Exception $e) {
            \Log::error("getSellerCobradoLite error: " . $e->getMessage());
            return $this->errorResponse('Error al obtener los pagos del vendedor', 500);
        }
    }

    public function show($creditId, $paymentId)
    {
        try {
            return $this->paymentService->show($creditId, $paymentId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getTotalWithoutInstallments(Request $request, $creditId)
    {
        try {

            return $this->paymentService->getTotalWithoutInstallments($creditId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($paymentId, Request $request)
    {
        \App\Support\Tenant::assertPaymentInScope($paymentId);
        try {
            return $this->paymentService->delete($paymentId, $request);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function deletePaymentInstallment($paymentInstallmentId, Request $request)
    {
        \App\Support\Tenant::assertPaymentInstallmentInScope($paymentInstallmentId);
        try {
            return $this->paymentService->deletePaymentInstallment($paymentInstallmentId, $request);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function dailyPaymentTotals(Request $request)
    {
        $date = $request->get('date');
        $timezone = $request->get('timezone', 'America/Lima');

        $start = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()->timezone('UTC');
        $end = Carbon::createFromFormat('Y-m-d', $date, $timezone)->endOfDay()->timezone('UTC');
        $todayDate = Carbon::now($timezone)->toDateString();
        $user = Auth::user();

        if (!in_array($user->role_id, [1, 2, 5, 11])) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }

        $sellerId = null;
        if ($user->role_id == 5) {
            $sellerId = $user->seller->id;
        }

        // 1. Pagos del día (Total Cobrado)
        $paymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->where('payments.business_date', $date)
            ->whereNull('payments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at');

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->join('clients', 'credits.client_id', '=', 'clients.id')
            ->select(DB::raw('MIN(payments.created_at) as first_payment_date'))
            ->where('payments.business_date', $date)
            ->whereNull('payments.deleted_at')
            ->whereNull('credits.deleted_at')
            ->whereNull('clients.deleted_at');

        if ($sellerId) {
            $paymentQuery->where('credits.seller_id', $sellerId);
            $firstPaymentQuery->where('credits.seller_id', $sellerId);
        }

        $paymentResults = $paymentQuery->groupBy('payments.payment_method')
            ->get();

        $firstPaymentResult = $firstPaymentQuery->first();
        $firstPaymentDate = null;
        if ($firstPaymentResult && $firstPaymentResult->first_payment_date) {
            $firstPaymentDate = Carbon::parse($firstPaymentResult->first_payment_date)
                ->setTimezone($timezone)
                ->toDateTimeString();
        }

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0, // Valor Total Cobrado
            'liquidation_start_date' => $firstPaymentDate
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

        // 2. Total esperado (cuotas vencidas para hoy)
        $expectedQuery = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('installments.due_date', $date)
            ->whereNull('credits.deleted_at')
            ->select(DB::raw('COALESCE(SUM(installments.quota_amount), 0) as total'));

        if ($sellerId) {
            $expectedQuery->where('credits.seller_id', $sellerId);
        }

        $expectedResult = $expectedQuery->first();
        $totals['expected_total'] = (float) $expectedResult->total;

        // 3. Créditos creados (Base Entregado = 0 según requerimiento)
        $createdCreditsQuery = DB::table('credits')
            ->select(
                DB::raw('COALESCE(SUM(credit_value), 0) as total_credit_value'),
                DB::raw('COALESCE(SUM(credit_value * (total_interest / 100)), 0) as total_interest_amount')
            )
            ->whereBetween('created_at', [$start, $end])
            ->whereNull('deleted_at')
            ->whereNull('unification_reason')
            ->whereNull('renewed_from_id');

        $renewalCreditsQuery = DB::table('credits')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('renewed_from_id');



        if ($sellerId) {
            $createdCreditsQuery->where('seller_id', $sellerId);
            $renewalCreditsQuery->where('seller_id', $sellerId);
        }

        $createdCreditsResult = $createdCreditsQuery->first();


        $totals['created_credits_value'] = (float) $createdCreditsResult->total_credit_value;
        $totals['created_credits_interest'] = (float) $createdCreditsResult->total_interest_amount;

        $total_renewal_disbursed = 0;
        $total_pending_absorbed = 0;

        $renewalCredits = $renewalCreditsQuery->get();


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
        }

        $totals['total_renewal_disbursed'] = $total_renewal_disbursed;
        $totals['total_crossed_credits'] = $total_pending_absorbed;

        // Ajuste clave: Base Entregado siempre en 0
        $totals['base_value'] = 0;

        // 4. Total clientes
        $clientsQuery = DB::table('clients')
            ->select(DB::raw('COUNT(id) as total_clients'));

        if ($sellerId) {
            $clientsQuery->whereExists(function ($query) use ($sellerId) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->where('credits.seller_id', $sellerId);
            });
        }

        $clientsResult = $clientsQuery->first();
        $totals['total_clients'] = (int) ($clientsResult->total_clients ?? 0);

        // 5. Gastos
        $expensesQuery = DB::table('expenses')
            ->select(DB::raw('COALESCE(SUM(value), 0) as total_expenses'))
            ->whereNull('deleted_at')
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('status', 'Aprobado')
                    ->orWhere('description', 'like', '%AJUSTE%');
            });

        $incomeQuery = DB::table('incomes')
            ->select(DB::raw('COALESCE(SUM(value), 0) as total_income'))
            ->whereNull('deleted_at')
            ->where('business_date', $date);

        if ($user->role_id == 5) {
            $expensesQuery->where('user_id', $user->id);
            $incomeQuery->where('user_id', $user->id);
        }

        $expensesResult = $expensesQuery->first();

        // List all expenses for the date
        $expensesListQuery = DB::table('expenses')
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('status', 'Aprobado')
                    ->orWhere('description', 'like', '%AJUSTE%');
            });

        if ($user->role_id == 5) {
            $expensesListQuery = $expensesListQuery->where('user_id', $user->id);
        }
        $expensesList = $expensesListQuery->get();
        $totals['total_expenses'] = (float) ($expensesResult->total_expenses ?? 0);

        $incomeResult = $incomeQuery->first();

        // List all incomes for the date
        $incomesListQuery = DB::table('incomes')
            ->where('business_date', $date);

        if ($user->role_id == 5) {
            $incomesListQuery = $incomesListQuery->where('user_id', $user->id);
        }
        $incomesList = $incomesListQuery->get();
        $totals['total_income'] = (float) ($incomeResult->total_income ?? 0);

        // List all payments for the date - CORREGIDO: usar created_at con rango UTC
        $paymentsListQuery = DB::table('payments')
            ->where('payments.business_date', $date);  // Especificamos la tabla

        if ($sellerId) {
            $paymentsListQuery = $paymentsListQuery
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $sellerId);
        }

        $paymentsList = $paymentsListQuery->get();

        $totals['poliza'] = (float) DB::table('credits')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNull('deleted_at')
            ->whereNull('unification_reason')
            ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

        // 6. Cálculo de saldos (Reestructurado según requerimiento)
        $initialCash = 0;
        if ($sellerId) {
            $lastLiquidation = Liquidation::where('seller_id', $sellerId)
                ->where('date', '<', $date)
                ->orderBy('date', 'desc')
                ->first();

            $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;
        }

        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereDate('credits.updated_at', $todayDate)
            ->whereNull('credits.deleted_at')
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        $realToDeliver = $initialCash
            + ($totals['total_income'] + $totals['collected_total'] + $totals['poliza'])
            - ($totals['created_credits_value']
                + $totals['total_expenses']
                + $totals['total_renewal_disbursed']
                + $irrecoverableCredits);

        $cashCollection = ($totals['total_income'] + $totals['collected_total'] + $totals['poliza'])
            - ($totals['created_credits_value']
                + $totals['total_expenses']
                + $totals['total_renewal_disbursed']
                + $irrecoverableCredits);

        $totals['initial_cash'] = $initialCash;
        $totals['real_to_deliver'] = $realToDeliver;
        $totals['cash_collection'] = $cashCollection;

        // Valor Total de Entrega = Saldo inicial + Total Cobrado
        $totals['total_delivery_value'] = $initialCash + $totals['collected_total'];

        // Saldo Actual (Valor real a entregar) = (Saldo inicial + Total Cobrado) - Gastos
        $totals['current_balance'] = $totals['total_delivery_value'] - $totals['total_expenses'];

        // Verificación: Saldo Actual debe ser igual a Créditos Nuevos
        // (current_balance = created_credits_value según lógica del negocio)
        $totals['daily_goal'] = $totals['expected_total'];

        return response()->json([
            'success' => true,
            'data' => $totals
        ]);
    }

    public function paymentsByDate(Request $request)
    {
        $date = $request->get('date');
        $sellerId = $request->get('seller_id');
        if (!$date) {
            return response()->json(['success' => false, 'message' => 'Debe enviar la fecha.'], 400);
        }
        try {
            $result = $this->paymentService->getPaymentsByDate($date, $request, $sellerId);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function reapply($creditId)
    {
        \App\Support\Tenant::assertCreditInScope($creditId);
        try {
            return $this->paymentService->reapplyPayments($creditId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
