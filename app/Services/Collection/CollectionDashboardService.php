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
        $countryCode = $request->input('country_code');

        // IDs de créditos filtrados por país (si se especifica)
        $creditIdsForCountry = null;
        if ($countryCode) {
            $creditIdsForCountry = CollectionCredit::where('company_id', $companyId)
                ->where('country_code', $countryCode)
                ->pluck('id');
        }

        // ── FLUJO DE CAJA HOY ──
        $paymentsQ = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')->whereDate('recorded_at', $today);
        if (!$isAdmin) $paymentsQ->where('user_id', $user->id);
        if ($creditIdsForCountry !== null) $paymentsQ->whereIn('credit_id', $creditIdsForCountry);
        $collectedToday = (float) $paymentsQ->sum('amount_paid');
        $paymentsCount = $paymentsQ->count();

        $expensesQ = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->whereDate('recorded_at', $today)->where('status', 'approved');
        if (!$isAdmin) $expensesQ->where('user_id', $user->id);
        $expensesToday = (float) $expensesQ->sum('amount');

        // ── CARTERA (capital + interes) ──
        $activeCreditsQ = CollectionCredit::where('company_id', $companyId)->where('status', 'active');
        if ($countryCode) $activeCreditsQ->where('country_code', $countryCode);
        $activeCredits = $activeCreditsQ->count();
        $totalCapitalDisbursed = (float) (clone $activeCreditsQ)->sum('amount');

        // Totales de cuotas: separamos principal (capital) e interes
        $portfolioTotalsQ = CollectionInstallment::where('company_id', $companyId)
            ->whereIn('status', ['pendiente', 'parcial', 'pagado']);
        if ($creditIdsForCountry !== null) $portfolioTotalsQ->whereIn('credit_id', $creditIdsForCountry);
        $portfolioTotals = $portfolioTotalsQ
            ->select(
                DB::raw('COALESCE(SUM(principal_amount), 0) as total_principal'),
                DB::raw('COALESCE(SUM(interest_amount), 0) as total_interest'),
                DB::raw('COALESCE(SUM(amount), 0) as total_amount'),
                DB::raw('COALESCE(SUM(principal_paid), 0) as paid_principal'),
                DB::raw('COALESCE(SUM(interest_paid), 0) as paid_interest'),
                DB::raw('COALESCE(SUM(paid_amount), 0) as paid_total')
            )->first();

        // Capital real desembolsado (suma de montos de créditos activos)
        $totalPrincipal = $totalCapitalDisbursed;
        // Datos de cuotas generadas
        $installmentPrincipal = (float) ($portfolioTotals->total_principal ?? 0);
        $totalInterest = (float) ($portfolioTotals->total_interest ?? 0);
        $totalCartera = $totalPrincipal + $totalInterest;
        $paidPrincipal = (float) ($portfolioTotals->paid_principal ?? 0);
        $paidInterest = (float) ($portfolioTotals->paid_interest ?? 0);
        $paidTotal = (float) ($portfolioTotals->paid_total ?? 0);

        // Pendiente por cobrar
        $pendingPrincipal = max(0, $totalPrincipal - $paidPrincipal);
        $pendingInterest = max(0, $totalInterest - $paidInterest);
        $pendingTotal = $pendingPrincipal + $pendingInterest;

        // % recuperacion de capital (sobre capital real desembolsado)
        $capitalRecoveryRate = $totalPrincipal > 0 ? round(($paidPrincipal / $totalPrincipal) * 100, 1) : 0;
        // % interes cobrado (ganancia realizada)
        $interestRealizedRate = $totalInterest > 0 ? round(($paidInterest / $totalInterest) * 100, 1) : 0;
        // Margen de ganancia proyectado
        $profitMargin = $totalPrincipal > 0 ? round(($totalInterest / $totalPrincipal) * 100, 1) : 0;

        $totalPortfolio = $pendingTotal;

        // ── MOROSIDAD ──
        $sevenDaysAgo = Carbon::now()->subDays(7)->toDateString();
        $overdueQ = CollectionInstallment::where('company_id', $companyId)
            ->where('status', 'pendiente')->where('due_date', '<', $today);
        if ($creditIdsForCountry !== null) $overdueQ->whereIn('credit_id', $creditIdsForCountry);
        $overdueData = $overdueQ->select(
                DB::raw('COUNT(*) as total_count'),
                DB::raw('COALESCE(SUM(amount - paid_amount), 0) as total_amount'),
                DB::raw("COUNT(CASE WHEN due_date < '{$sevenDaysAgo}' THEN 1 END) as critical_count"),
                DB::raw("COALESCE(SUM(CASE WHEN due_date < '{$sevenDaysAgo}' THEN amount - paid_amount ELSE 0 END), 0) as critical_amount")
            )->first();

        $overdueCount = (int) ($overdueData->total_count ?? 0);
        $overdueAmount = (float) ($overdueData->total_amount ?? 0);
        $totalDueQ = CollectionInstallment::where('company_id', $companyId)
            ->where('due_date', '<=', $today);
        if ($creditIdsForCountry !== null) $totalDueQ->whereIn('credit_id', $creditIdsForCountry);
        $totalDueInstallments = $totalDueQ->count();
        $delinquencyRate = $totalDueInstallments > 0 ? round(($overdueCount / $totalDueInstallments) * 100, 1) : 0;

        // ── PROXIMOS VENCIMIENTOS (7 dias) ──
        $upcomingQ = CollectionInstallment::where('collection_installments.company_id', $companyId)
            ->whereIn('collection_installments.status', ['pendiente', 'parcial'])
            ->whereBetween('collection_installments.due_date', [$today, Carbon::now()->addDays(7)->toDateString()]);
        if ($creditIdsForCountry !== null) $upcomingQ->whereIn('collection_installments.credit_id', $creditIdsForCountry);
        $upcoming = $upcomingQ->join('collection_credits', function ($j) {
                $j->on('collection_installments.credit_id', '=', 'collection_credits.id')
                  ->on('collection_installments.company_id', '=', 'collection_credits.company_id');
            })
            ->join('collection_clients', function ($j) {
                $j->on('collection_credits.client_id', '=', 'collection_clients.id')
                  ->on('collection_credits.company_id', '=', 'collection_clients.company_id');
            })
            ->select(
                'collection_installments.id', 'collection_installments.due_date',
                'collection_installments.amount', 'collection_installments.paid_amount',
                'collection_installments.installment_number',
                'collection_credits.id as credit_id',
                'collection_clients.name as client_name', 'collection_clients.dni as client_dni'
            )
            ->orderBy('collection_installments.due_date')
            ->limit(8)->get()
            ->map(fn($i) => [
                'id' => $i->id, 'client_name' => $i->client_name,
                'credit_id' => $i->credit_id, 'installment_number' => $i->installment_number,
                'due_date' => $i->due_date,
                'pending' => round((float) $i->amount - (float) $i->paid_amount, 2),
                'days_until' => (int) Carbon::parse($i->due_date)->diffInDays($today, false),
            ]);

        // ── ACTIVIDAD RECIENTE ──
        $recentPaymentsQ = CollectionPayment::where('company_id', $companyId)
            ->whereNull('deleted_at')->orderByDesc('recorded_at');
        if ($creditIdsForCountry !== null) $recentPaymentsQ->whereIn('credit_id', $creditIdsForCountry);
        $recentPayments = $recentPaymentsQ->limit(8)
            ->get()->map(fn($p) => [
                'type' => 'payment', 'amount' => (float) $p->amount_paid,
                'description' => "Cobro cuota #{$p->installment_number}",
                'recorded_at' => $p->recorded_at, 'icon' => 'south_west', 'color' => 'green'
            ]);
        $recentExpenses = CollectionExpense::where('company_id', $companyId)
            ->whereNull('deleted_at')->orderByDesc('recorded_at')->limit(8)
            ->get()->map(fn($e) => [
                'type' => 'expense', 'amount' => (float) $e->amount,
                'description' => $e->description ?: $e->category,
                'recorded_at' => $e->recorded_at, 'icon' => 'north_east', 'color' => 'red'
            ]);
        $recentActivity = $recentPayments->concat($recentExpenses)
            ->sortByDesc('recorded_at')->values()->take(10);

        // ── WALLETS + AUTH ──
        $wallets = $isAdmin ? app(CollectionWalletService::class)->getBalances($companyId) : [];
        $pendingAuths = $isAdmin
            ? \App\Models\Collection\CollectionAuthCode::where('company_id', $companyId)
                ->where('status', 'pending')->where('expires_at', '>', Carbon::now())->get()
            : [];

        return $this->successResponse([
            'kpis' => [
                'collected_today' => $collectedToday,
                'payments_count' => $paymentsCount,
                'expenses_today' => $expensesToday,
                'net_today' => $collectedToday - $expensesToday,
                'active_portfolio' => $totalPortfolio,
                'active_credits_count' => $activeCredits,
            ],
            'portfolio' => [
                'total_cartera' => $totalCartera,           // Capital + Interes proyectado
                'total_principal' => $totalPrincipal,        // Solo capital colocado
                'total_interest' => $totalInterest,          // Solo interes proyectado (ganancia)
                'paid_total' => $paidTotal,                  // Todo lo cobrado
                'paid_principal' => $paidPrincipal,          // Capital recuperado
                'paid_interest' => $paidInterest,            // Interes cobrado (ganancia realizada)
                'pending_total' => $pendingTotal,            // Saldo total pendiente
                'pending_principal' => $pendingPrincipal,    // Capital por recuperar
                'pending_interest' => $pendingInterest,      // Interes por cobrar
                'capital_recovery_rate' => $capitalRecoveryRate,   // % capital recuperado
                'interest_realized_rate' => $interestRealizedRate, // % interes cobrado
                'profit_margin' => $profitMargin,            // Margen de ganancia proyectado
            ],
            'delinquency' => [
                'overdue_count' => $overdueCount,
                'overdue_amount' => $overdueAmount,
                'critical_count' => (int) ($overdueData->critical_count ?? 0),
                'critical_amount' => (float) ($overdueData->critical_amount ?? 0),
                'rate' => $delinquencyRate,
            ],
            'upcoming_due' => $upcoming,
            'recent_activity' => $recentActivity,
            'wallets' => $wallets,
            'pending_authorizations' => $pendingAuths,
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
