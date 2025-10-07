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
            $perPage = $request->get('perPage') ?? 5;

            return $this->paymentService->index($creditId, $request, $perPage);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function indexBySeller(Request $request, $sellerId)
    {
        try {
            $perPage = $request->get('perPage', 10);

            return $this->paymentService->getPaymentsBySeller($sellerId, $request, $perPage);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
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

    public function delete($paymentId)
    {
        try {
            return $this->paymentService->delete($paymentId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function dailyPaymentTotals(Request $request)
    {
        $date = $request->get('date');
        $timezone = 'America/Caracas'; // Zona horaria de Venezuela

        $start = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()->timezone('UTC');
        $end = Carbon::createFromFormat('Y-m-d', $date, $timezone)->endOfDay()->timezone('UTC');

        $user = Auth::user();

        if (!in_array($user->role_id, [1, 2, 5])) {
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
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereBetween('payments.created_at', [$start, $end]);

        $firstPaymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(DB::raw('MIN(payments.created_at) as first_payment_date'))
            ->whereBetween('payments.created_at', [$start, $end]);

        if ($sellerId) {
            $paymentQuery->where('credits.seller_id', $sellerId);
            $firstPaymentQuery->where('credits.seller_id', $sellerId);
        }

        $paymentResults = $paymentQuery->groupBy('payments.payment_method')
            ->get();

        $firstPaymentResult = $firstPaymentQuery->first();
        $firstPaymentDate = $firstPaymentResult ? $firstPaymentResult->first_payment_date : null;

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0, // Valor Total Cobrado
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

        // 2. Total esperado (cuotas vencidas para hoy)
        $expectedQuery = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('installments.due_date', $date)
            ->select(DB::raw('COALESCE(SUM(installments.quota_amount), 0) as total'));

        if ($sellerId) {
            $expectedQuery->where('credits.seller_id', $sellerId);
        }

        $expectedResult = $expectedQuery->first();
        $totals['expected_total'] = (float)$expectedResult->total;

        // 3. Créditos creados (Base Entregado = 0 según requerimiento)
        $createdCreditsQuery = DB::table('credits')
            ->select(
                DB::raw('COALESCE(SUM(credit_value), 0) as total_credit_value'),
                DB::raw('COALESCE(SUM(credit_value * (total_interest / 100)), 0) as total_interest_amount')
            )
            ->whereBetween('created_at', [$start, $end])
            ->whereNull('unification_reason');

        if ($sellerId) {
            $createdCreditsQuery->where('seller_id', $sellerId);
        }

        $createdCreditsResult = $createdCreditsQuery->first();

        $totals['created_credits_value'] = (float)$createdCreditsResult->total_credit_value;
        $totals['created_credits_interest'] = (float)$createdCreditsResult->total_interest_amount;

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
        $totals['total_clients'] = (int)($clientsResult->total_clients ?? 0);

        // 5. Gastos
        $expensesQuery = DB::table('expenses')
            ->select(DB::raw('COALESCE(SUM(value), 0) as total_expenses'))
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'Aprobado');

        $incomeQuery = DB::table('incomes')
            ->select(DB::raw('COALESCE(SUM(value), 0) as total_income'))
            ->whereBetween('created_at', [$start, $end]);

        if ($user->role_id == 5) {
            $expensesQuery->where('user_id', $user->id);
            $incomeQuery->where('user_id', $user->id);
        }

        $expensesResult = $expensesQuery->first();

        // List all expenses for the date - CORREGIDO: usar created_at con rango UTC
        $expensesListQuery = DB::table('expenses')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'Aprobado');

        if ($user->role_id == 5) {
            $expensesListQuery = $expensesListQuery->where('user_id', $user->id);
        }
        $expensesList = $expensesListQuery->get();
        $totals['total_expenses'] = (float)($expensesResult->total_expenses ?? 0);

        $incomeResult = $incomeQuery->first();

        // List all incomes for the date - CORREGIDO: usar created_at con rango UTC
        $incomesListQuery = DB::table('incomes')
            ->whereBetween('created_at', [$start, $end]);

        if ($user->role_id == 5) {
            $incomesListQuery = $incomesListQuery->where('user_id', $user->id);
        }
        $incomesList = $incomesListQuery->get();
        $totals['total_income'] = (float)($incomeResult->total_income ?? 0);

        // List all payments for the date - CORREGIDO: usar created_at con rango UTC
        $paymentsListQuery = DB::table('payments')
            ->whereBetween('payments.created_at', [$start, $end]);  // Especificamos la tabla

        if ($sellerId) {
            $paymentsListQuery = $paymentsListQuery
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->where('credits.seller_id', $sellerId);
        }

        $paymentsList = $paymentsListQuery->get();

        // 6. Cálculo de saldos (Reestructurado según requerimiento)
        $initialCash = 0;
        if ($sellerId) {
            $lastLiquidation = Liquidation::where('seller_id', $sellerId)
                ->where('date', '<', $date)
                ->orderBy('date', 'desc')
                ->first();

            $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;
        }

        // CORREGIDO: Usar Carbon con timezone para el cálculo de la fecha anterior
        $previousDate = Carbon::createFromFormat('Y-m-d', $date, $timezone)
            ->subDay()
            ->format('Y-m-d');

        $irrecoverableCredits = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('credits.seller_id', $sellerId)
            ->where('credits.status', 'Cartera Irrecuperable')
            ->whereDate('credits.updated_at', $previousDate)
            ->where('installments.status', 'Pendiente')
            ->sum('installments.quota_amount');

        $realToDeliver = $initialCash
            + ($totals['total_income'] + $totals['collected_total'])
            - ($totals['created_credits_value']
                + $totals['total_expenses'] + $irrecoverableCredits);

        $totals['initial_cash'] = $initialCash;
        $totals['real_to_deliver'] = $realToDeliver;

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
}
