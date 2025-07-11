<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PaymentService;
use App\Http\Requests\Payment\PaymentRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            $page = $request->get('page', 1);
            $perPage = $request->get('perPage', 10);

            return $this->paymentService->index($creditId, $request, $page, $perPage);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function indexBySeller(Request $request, $sellerId)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('perPage', 10);

            return $this->paymentService->getPaymentsBySeller($sellerId, $request, $page, $perPage);
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
        $date = $request->input('date');
        $user = Auth::user();

        if (!in_array($user->role_id, [1, 2, 5])) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }

        $paymentQuery = DB::table('payments')
            ->join('credits', 'payments.credit_id', '=', 'credits.id')
            ->select(
                'payments.payment_method',
                DB::raw('SUM(payments.amount) as total')
            )
            ->whereDate('payments.payment_date', $date);

        if ($user->role_id == 5) {
            $paymentQuery->where('credits.seller_id', $user->seller->id);
        }

        $paymentResults = $paymentQuery->groupBy('payments.payment_method')
            ->get();

        $totals = [
            'cash' => 0,
            'transfer' => 0,
            'collected_total' => 0,
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

        $expectedQuery = DB::table('installments')
            ->join('credits', 'installments.credit_id', '=', 'credits.id')
            ->where('installments.due_date', $date)
            ->select(DB::raw('COALESCE(SUM(installments.quota_amount), 0) as total'));

        if ($user->role_id == 5) {
            $expectedQuery->where('credits.seller_id', $user->seller->id);
        }

        $expectedResult = $expectedQuery->first();
        $totals['expected_total'] = (float)$expectedResult->total;

        $createdCreditsQuery = DB::table('credits')
            ->select(
                DB::raw('COALESCE(SUM(credit_value), 0) as total_credit_value'),
                DB::raw('COALESCE(SUM(credit_value * (total_interest / 100)), 0) as total_interest_amount')
            )
            ->whereDate('created_at', $date);

        if ($user->role_id == 5) {
            $createdCreditsQuery->where('seller_id', $user->seller->id);
        }

        $createdCreditsResult = $createdCreditsQuery->first();

        $totals['created_credits_value'] = (float)$createdCreditsResult->total_credit_value;
        $totals['created_credits_interest'] = (float)$createdCreditsResult->total_interest_amount;

        $clientsQuery = DB::table('clients')
            ->select(DB::raw('COUNT(id) as total_clients'));

        if ($user->role_id == 5) {
            $clientsQuery->whereExists(function ($query) use ($user) {
                $query->select(DB::raw(1))
                    ->from('credits')
                    ->whereColumn('credits.client_id', 'clients.id')
                    ->where('credits.seller_id', $user->seller->id);
            });
        }

        $clientsResult = $clientsQuery->first();
        $totals['total_clients'] = (int)($clientsResult->total_clients ?? 0);

        $initialCash = 0;

        $netCredit = $totals['created_credits_value'] - $totals['collected_total'];
        $finalCash = ($initialCash + $netCredit + $totals['created_credits_interest']) - $totals['cash'];
    
        $totals['initial_cash'] = $initialCash;
        $totals['net_credit'] = $netCredit;
        $totals['final_cash'] = $finalCash;

        return response()->json([
            'success' => true,
            'data' => $totals
        ]);
    }
}
