<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCredit;
use App\Models\Collection\CollectionInstallment;
use App\Models\Collection\CollectionPayment;
use App\Models\Collection\CollectionExpense;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CollectionDashboardService
{
    use ApiResponse;

    public function getSummary($request)
    {
        $companyId = $this->resolveCompanyId($request->company_id);
        if (!$companyId) return $this->errorResponse('Empresa no identificada', 422);

        $today = Carbon::now()->toDateString();
        $user = Auth::user();
        $isAdmin = in_array($user->role_id, [1, 2]);

        // KPI 1: Recaudado Hoy
        $paymentsQuery = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('recorded_at', $today);
        if (!$isAdmin) $paymentsQuery->where('user_id', $user->id);
        
        $totalCollectedToday = (float) $paymentsQuery->sum('amount_paid');
        $paymentsCount = $paymentsQuery->count();

        // KPI 2: Cartera Activa (Total pendiente)
        $portfolioQuery = CollectionInstallment::where('company_id', $companyId)
            ->whereIn('status', ['pendiente', 'parcial']);
        
        // Note: For portfolio, we usually see the whole company visibility unless restricted
        $totalPortfolio = (float) $portfolioQuery->sum(DB::raw('amount - paid_amount'));
        $activeCreditsCount = CollectionCredit::where('company_id', $companyId)->where('status', 'active')->count();

        // KPI 3: Gastos Hoy
        $expensesQuery = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('recorded_at', $today)
            ->where('status', 'approved');
        if (!$isAdmin) $expensesQuery->where('user_id', $user->id);
        
        $totalExpensesToday = (float) $expensesQuery->sum('amount');

        // Recent Activity (Mixed)
        $recentPayments = CollectionPayment::with('client')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('recorded_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($p) {
                return [
                    'type' => 'payment',
                    'id' => $p->id,
                    'amount' => (float)$p->amount_paid,
                    'description' => "Abono de " . ($p->client->name ?? 'Cliente'),
                    'recorded_at' => $p->recorded_at,
                    'category' => 'Ingreso',
                    'icon' => 'payments',
                    'color' => 'green'
                ];
            });

        $recentExpenses = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('recorded_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($e) {
                return [
                    'type' => 'expense',
                    'id' => $e->id,
                    'amount' => (float)$e->amount,
                    'description' => $e->description ?: "Gasto de {$e->category}",
                    'recorded_at' => $e->recorded_at,
                    'category' => $e->category,
                    'icon' => 'receipt',
                    'color' => 'red'
                ];
            });

        $recentActivity = $recentPayments->concat($recentExpenses)
            ->sortByDesc('recorded_at')
            ->values()
            ->take(15);

        // For Admins/Managers: Pending security authorizations
        $pendingAuths = [];
        if ($isAdmin) {
            $pendingAuths = \App\Models\Collection\CollectionAuthCode::where('company_id', $companyId)
                ->where('status', 'pending')
                ->where('expires_at', '>', Carbon::now())
                ->get();
        }

        // For Admins/Managers: Centralized Wallets
        $wallets = [];
        if ($isAdmin) {
            $wallets = app(\App\Services\Collection\CollectionWalletService::class)->getBalances($companyId);
        }

        return $this->successResponse([
            'kpis' => [
                'collected_today' => $totalCollectedToday,
                'payments_count' => $paymentsCount,
                'active_portfolio' => $totalPortfolio,
                'active_credits_count' => $activeCreditsCount,
                'expenses_today' => $totalExpensesToday,
            ],
            'recent_activity' => $recentActivity,
            'pending_authorizations' => $pendingAuths,
            'wallets' => $wallets
        ]);
    }

    private function resolveCompanyId($requestedId)
    {
        if ($requestedId) return (int) $requestedId;
        $user = Auth::user();
        if (!$user) return null;
        return $user->company->id ?? $user->seller->company_id ?? null;
    }
}
