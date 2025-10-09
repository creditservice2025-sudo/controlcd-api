<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Seller;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    use ApiResponse;

    public function loadCounters(Request $request)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $data = [
                'members' => 0,
                'routes' => 0,
                'credits' => 0,
                'clients' => 0,
            ];

            if ($role === 1) {
                $data['members'] = User::count();
                $data['routes'] = Seller::count();
                $data['credits'] = Credit::count();
                $data['clients'] = Client::count();
            } elseif ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => $data,
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }

                $companyId = $user->company->id;

                $data['routes'] = Seller::where('company_id', $companyId)->count();

                $data['members'] = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->count();

                $sellerIds = Seller::where('company_id', $companyId)->pluck('id');
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)->count();
            } elseif ($role === 5) {
                $seller = $user->seller;
                if ($seller) {
                    $data['clients'] = $seller->clients()->count();
                    $data['credits'] = $seller->credits()->count();
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error("Error loading counters: " . $e->getMessage());
            return $this->errorResponse('Error al obtener el conteo de datos.', 500);
        }
    }

    public function loadPendingPortfolios()
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $timezone = 'America/Caracas';
            $startUTC = Carbon::now($timezone)->startOfDay()->timezone('UTC');
            $endUTC = Carbon::now($timezone)->endOfDay()->timezone('UTC');
            $todayDate = Carbon::now($timezone)->toDateString();

            $sellersQuery = Seller::with([
                'clients',
                'credits.installments.payments',
                'city' => function ($query) {
                    $query->select('id', 'name', 'country_id');
                },
                'city.country' => function ($query) {
                    $query->select('id', 'name');
                },
                'user' => function ($query) {
                    $query->select('id', 'name');
                }
            ])->orderBy('created_at', 'desc');

            if ($role === 1) {
                // Admin: no filtro
            } elseif ($role === 2) {
                if (!$user->company) {
                    return response()->json([
                        'success' => true,
                        'data' => []
                    ]);
                }
                $sellersQuery->where('company_id', $user->company->id);
            } elseif ($role === 5) {
                $sellersQuery->where('user_id', $user->id);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $sellers = $sellersQuery->take(10)->get();

            $result = [];

            foreach ($sellers as $index => $seller) {
                $location = $this->getSellerLocation($seller);

                $sellerData = [
                    'id' => $seller->id,
                    'route' => $seller->name,
                    'location' => $location,
                    'capital' => 0,
                    'utility' => 0,
                    'credits' => 0,
                    'name' => $seller->user ? $seller->user->name : 'Sin nombre',
                    'total' => 0,
                    'paidCredits' => 0,
                    'unpaidCredits' => 0,
                    'paidCapital' => 0,
                    'paidUtility' => 0,
                    'irrecoverableCredits' => 0,
                    'irrecoverableTotal' => 0,
                    'valorTotalCreditos' => 0,
                    'valorTotalPagado' => 0,
                    'valorPendiente' => 0,
                    'capitalBruto' => 0,
                    'utilidadBruta' => 0,
                ];

                foreach ($seller->credits as $credit) {
                    if ($credit->status === 'Cartera Irrecuperable') {
                        $sellerData['irrecoverableCredits']++;
                        $sellerData['irrecoverableTotal'] += $credit->credit_value + ($credit->credit_value * $credit->total_interest / 100);
                        continue;
                    }
                    $utility = $credit->credit_value * $credit->total_interest / 100;
                    $capitalPayable = $credit->credit_value;
                    $valorCredito = $capitalPayable + $utility;

                    $sellerData['valorTotalCreditos'] += $valorCredito;
                    $sellerData['capitalBruto'] += $capitalPayable;
                    $sellerData['utilidadBruta'] += $utility;

                    // Lógica por cuota (installment)
                    $pendingCapital = 0;
                    $pendingUtility = 0;
                    $paidCapital = 0;
                    $paidUtility = 0;

                    $installments = $credit->installments;
                    $numInstallments = $credit->number_installments > 0 ? $credit->number_installments : (count($installments) > 0 ? count($installments) : 1);
                    $capitalCuota = $credit->credit_value / $numInstallments;
                    $utilidadCuota = $utility / $numInstallments;

                    foreach ($installments as $installment) {
                        // Pagos aplicados a la cuota
                        $pagosCuota = ($installment->payments ?? collect())->sum('applied_amount');
                        // Descontar primero capital, luego utilidad
                        $capitalPagadoCuota = min($pagosCuota, $capitalCuota);
                        $utilityPagadoCuota = max(0, $pagosCuota - $capitalCuota);
                        $pendingCapital += max(0, $capitalCuota - $capitalPagadoCuota);
                        $pendingUtility += max(0, $utilidadCuota - $utilityPagadoCuota);
                        $paidCapital += $capitalPagadoCuota;
                        $paidUtility += $utilityPagadoCuota;
                    }

                    $totalPaid = Payment::where('credit_id', $credit->id)->sum('amount');
                    $sellerData['valorTotalPagado'] += $totalPaid;

                    $sellerData['capital'] += $pendingCapital;
                    $sellerData['utility'] += $pendingUtility;
                    $sellerData['total'] += $pendingCapital + $pendingUtility;

                    $sellerData['paidCapital'] += $paidCapital;
                    $sellerData['paidUtility'] += $paidUtility;

                    $sellerData['credits']++;

                    if ($pendingCapital == 0) {
                        $sellerData['paidCredits']++;
                    } else {
                        $sellerData['unpaidCredits']++;
                    }
                }
                $sellerData['valorPendiente'] = max(0, $sellerData['valorTotalCreditos'] - $sellerData['valorTotalPagado']);

                // --- Cálculo de caja actual por vendedor ---
                $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->orderBy('date', 'desc')
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $creditIds = collect($seller->credits)->pluck('id')->toArray();
                $cashPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->sum('amount');

                $expenses = Expense::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->where('status', 'Aprobado')
                    ->sum('value');

                $income = Income::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->sum('value');

                $newCredits = Credit::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->sum('credit_value');

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $todayDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $irrecoverableCredits);

                $sellerData['currentCash'] = (float) number_format($currentCash, 2, '.', '');
                $sellerData['initialCash'] = (float) number_format($initialCash, 2, '.', '');
                $sellerData['incomeToday'] = (float) number_format($income, 2, '.', '');
                $sellerData['expensesToday'] = (float) number_format($expenses, 2, '.', '');
                $sellerData['cashPaymentsToday'] = (float) number_format($cashPayments, 2, '.', '');
                $sellerData['newCreditsToday'] = (float) number_format($newCredits, 2, '.', '');
                $sellerData['irrecoverableCreditsYesterday'] = (float) number_format($irrecoverableCredits, 2, '.', '');

                $result[] = $sellerData;
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching pending portfolios: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las carteras pendientes'
            ], 500);
        }
    }

    private function getSellerLocation($seller)
    {
        if (!$seller->city) {
            return 'Ubicación no definida';
        }

        $city = $seller->city->name;
        $country = $seller->city->country->name ?? 'País no definido';

        return "$city, $country";
    }

    public function loadFinancialSummary(Request $request)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $timezone = 'America/Caracas';
            $today = Carbon::now($timezone)->toDateString();

            $startUTC = Carbon::now($timezone)->startOfDay()->timezone('UTC');
            $endUTC = Carbon::now($timezone)->endOfDay()->timezone('UTC');

            $totalBalance = 0;
            $capitalPending = 0;
            $profitPending = 0;
            $currentCash = 0;
            $incomeTotal = 0;
            $expenseTotal = 0;

            if ($role === 1) {
                $creditIds = Credit::pluck('id');

                if ($creditIds->isNotEmpty()) {
                    $totalBalance = Credit::whereIn('id', $creditIds)
                        ->sum(DB::raw('credit_value + (credit_value * total_interest / 100)'));

                    $incomeTotal = Income::sum('value');
                    $expenseTotal = Expense::sum('value');

                    $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', function ($query) use ($creditIds) {
                        $query->select('id')
                            ->from('installments')
                            ->whereIn('credit_id', $creditIds);
                    })->sum('applied_amount');

                    $totalPayments = Payment::whereIn('credit_id', $creditIds)->sum('amount');

                    $totalProfitPaid = $totalPayments - $totalCapitalPaid;

                    $capitalPending = $totalBalance - $totalCapitalPaid;

                    $totalExpectedProfit = Credit::whereIn('id', $creditIds)
                        ->sum(DB::raw('credit_value * total_interest / 100'));

                    $profitPending = $totalExpectedProfit - $totalProfitPaid;
                }

                $todayDate = Carbon::now($timezone)->toDateString();

                $lastLiquidation = Liquidation::orderBy('date', 'desc')->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $cashPayments = Payment::whereBetween('created_at', [$startUTC, $endUTC])->sum('amount');

                $expenses = Expense::whereBetween('created_at', [$startUTC, $endUTC])->sum('value');

                $income = Income::whereBetween('created_at', [$startUTC, $endUTC])->sum('value');

                $newCredits = Credit::whereBetween('created_at', [$startUTC, $endUTC])->sum('credit_value');

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $todayDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $irrecoverableCredits);
            } elseif ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [
                            'totalBalance' => 0,
                            'capital' => 0,
                            'profit' => 0,
                            'currentCash' => 0,
                            'income' => 0,
                            'expenses' => 0
                        ],
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }

                $companyId = $user->company->id;
                $sellerIds = Seller::where('company_id', $companyId)->pluck('id');

                if ($sellerIds->isNotEmpty()) {
                    $creditIds = Credit::whereIn('seller_id', $sellerIds)
                        ->pluck('id');

                    if ($creditIds->isNotEmpty()) {
                        $totalBalance = Credit::whereIn('id', $creditIds)
                            ->sum(DB::raw('credit_value + (credit_value * total_interest / 100)'));

                        $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', function ($query) use ($creditIds) {
                            $query->select('id')
                                ->from('installments')
                                ->whereIn('credit_id', $creditIds);
                        })->sum('applied_amount');

                        $totalPayments = Payment::whereIn('credit_id', $creditIds)->sum('amount');

                        $totalProfitPaid = $totalPayments - $totalCapitalPaid;

                        $capitalPending = $totalBalance - $totalCapitalPaid;

                        $totalExpectedProfit = Credit::whereIn('id', $creditIds)
                            ->sum(DB::raw('credit_value * total_interest / 100'));

                        $profitPending = $totalExpectedProfit - $totalProfitPaid;
                    }

                    $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                        $query->where('company_id', $companyId);
                    })->pluck('id');

                    $incomeTotal = Income::whereIn('user_id', $userIds)->sum('value');
                    $expenseTotal = Expense::whereIn('user_id', $userIds)->sum('value');

                    $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                        ->orderBy('date', 'desc')
                        ->first();
                    $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                    $cashPayments = Payment::whereIn('credit_id', $creditIds ?? [])
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('amount');

                    $expenses = Expense::whereIn('user_id', $userIds)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->where('status', 'Aprobado')
                        ->sum('value');

                    $income = Income::whereIn('user_id', $userIds)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('value');

                    $newCredits = Credit::whereIn('seller_id', $sellerIds)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('credit_value');

                    $todayDate = Carbon::now($timezone)->toDateString();

                    $irrecoverableCredits = DB::table('installments')
                        ->join('credits', 'installments.credit_id', '=', 'credits.id')
                        ->whereIn('credits.seller_id', $sellerIds)
                        ->where('credits.status', 'Cartera Irrecuperable')
                        ->whereDate('credits.updated_at', $todayDate)
                        ->where('installments.status', 'Pendiente')
                        ->sum('installments.quota_amount');

                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $irrecoverableCredits);
                }
            } elseif ($role === 5) {
                $seller = $user->seller;
                if ($seller) {

                    $creditIds = $seller->credits()
                        ->pluck('id');

                    if ($creditIds->isNotEmpty()) {
                        $totalBalance = Credit::whereIn('id', $creditIds)
                            ->sum(DB::raw('credit_value + (credit_value * total_interest / 100)'));

                        $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', function ($query) use ($creditIds) {
                            $query->select('id')
                                ->from('installments')
                                ->whereIn('credit_id', $creditIds);
                        })->sum('applied_amount');

                        $totalPayments = Payment::whereIn('credit_id', $creditIds)->sum('amount');

                        $totalProfitPaid = $totalPayments - $totalCapitalPaid;

                        $capitalPending = $totalBalance - $totalCapitalPaid;

                        $totalExpectedProfit = Credit::whereIn('id', $creditIds)
                            ->sum(DB::raw('credit_value * total_interest / 100'));

                        $profitPending = $totalExpectedProfit - $totalProfitPaid;
                    }

                    $incomeTotal = Income::where('user_id', $user->id)->sum('value');
                    $expenseTotal = Expense::where('user_id', $user->id)->sum('value');

                    $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                        ->orderBy('date', 'desc')
                        ->first();
                    $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                    $cashPayments = Payment::whereIn('credit_id', $creditIds ?? [])
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('amount');

                    $expenses = Expense::where('user_id', $user->id)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->where('status', 'Aprobado')
                        ->sum('value');

                    $income = Income::where('user_id', $user->id)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('value');

                    $newCredits = $seller->credits()
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->sum('credit_value');

                    $todayDate = Carbon::now($timezone)->toDateString();

                    $irrecoverableCredits = DB::table('installments')
                        ->join('credits', 'installments.credit_id', '=', 'credits.id')
                        ->where('credits.seller_id', $seller->id)
                        ->where('credits.status', 'Cartera Irrecuperable')
                        ->whereDate('credits.updated_at', $todayDate)
                        ->where('installments.status', 'Pendiente')
                        ->sum('installments.quota_amount');

                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $irrecoverableCredits);
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'totalBalance' => (float) number_format($totalBalance, 2, '.', ''),
                    'capital' => (float) number_format($capitalPending, 2, '.', ''),
                    'profit' => (float) number_format($profitPending, 2, '.', ''),
                    'currentCash' => (float) number_format($currentCash, 2, '.', ''),
                    'income' => (float) number_format($incomeTotal, 2, '.', ''),
                    'expenses' => (float) number_format($expenseTotal, 2, '.', '')
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error loading financial summary: " . $e->getMessage());
            return $this->errorResponse('Error al obtener el resumen financiero.', 500);
        }
    }

    public function weeklyFinancialSummary(Request $request)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;
            $timezone = 'America/Caracas';
            $startOfWeek = Carbon::now($timezone)->startOfWeek()->timezone('UTC');
            $endOfWeek = Carbon::now($timezone)->endOfWeek()->timezone('UTC');
            $todayDate = Carbon::now($timezone)->toDateString();

            $initialCash = 0;
            $income = 0;
            $cashPayments = 0;
            $newCredits = 0;
            $expenses = 0;
            $irrecoverableCredits = 0;

            if ($role === 1) { // Admin
                $lastLiquidation = Liquidation::orderBy('date', 'asc')
                    ->whereDate('date', $startOfWeek->toDateString())
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $income = Income::whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');
                $cashPayments = Payment::whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('amount');
                $newCredits = Credit::whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('credit_value');
                $expenses = Expense::whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [$startOfWeek, $endOfWeek])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');
            } elseif ($role === 2) { // Empresa
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }
                $companyId = $user->company->id;
                $sellerIds = Seller::where('company_id', $companyId)->pluck('id');
                $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');

                $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                    ->orderBy('date', 'asc')
                    ->whereDate('date', $startOfWeek->toDateString())
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->pluck('id');

                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');
                $cashPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('amount');
                $newCredits = Credit::whereIn('seller_id', $sellerIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('credit_value');
                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->whereIn('credits.seller_id', $sellerIds)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [$startOfWeek, $endOfWeek])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');
            } elseif ($role === 5) { // Vendedor
                $seller = $user->seller;
                $creditIds = Credit::where('seller_id', $seller->id)->pluck('id');
                if (!$seller) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene vendedor asociado'
                    ]);
                }

                $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->orderBy('date', 'asc')
                    ->whereDate('date', $startOfWeek->toDateString())
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $income = Income::where('user_id', $user->id)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');
                $cashPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('amount');
                $newCredits = Credit::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('credit_value');
                $expenses = Expense::where('user_id', $user->id)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [$startOfWeek, $endOfWeek])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');
            }

            $balanceGeneral = $initialCash + ($income + $cashPayments) - ($newCredits + $expenses + $irrecoverableCredits);

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'balanceGeneral' => (float) number_format($balanceGeneral, 2, '.', ''),
                    'initialCash' => (float) $initialCash,
                    'incomeWeek' => (float) $income,
                    'cashPaymentsWeek' => (float) $cashPayments,
                    'newCreditsWeek' => (float) $newCredits,
                    'expensesWeek' => (float) $expenses,
                    'irrecoverableWeek' => (float) $irrecoverableCredits
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error loading weekly financial summary: " . $e->getMessage());
            return $this->errorResponse('Error al obtener el balance general semanal.', 500);
        }
    }
    public function weeklyMovements(Request $request)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;
            $timezone = 'America/Caracas';
            $filter = $request->input('filter', 'day'); // 'day', 'week', 'month'

            if ($filter === 'week') {
                $start = Carbon::now($timezone)->startOfWeek()->timezone('UTC');
                $end = Carbon::now($timezone)->endOfWeek()->timezone('UTC');
            } elseif ($filter === 'month') {
                $start = Carbon::now($timezone)->startOfMonth()->timezone('UTC');
                $end = Carbon::now($timezone)->endOfMonth()->timezone('UTC');
            } else {
                $start = Carbon::now($timezone)->startOfDay()->timezone('UTC');
                $end = Carbon::now($timezone)->endOfDay()->timezone('UTC');
            }

            $income = 0;
            $expenses = 0;
            $collected = 0;
            $newCredits = 0;
            $policy = 0; // Si tienes tabla de pólizas, agrega aquí
            $profit = 0;

            if ($role === 1) { // Admin
                $income = Income::whereBetween('created_at', [$start, $end])->sum('value');
                $expenses = Expense::whereBetween('created_at', [$start, $end])->sum('value');
                $collected = Payment::whereBetween('created_at', [$start, $end])->sum('amount');
                $newCredits = Credit::whereBetween('created_at', [$start, $end])->sum('credit_value');
                // $policy = Policy::whereBetween('created_at', [$start, $end])->sum('value');
                $totalCapitalPaid = PaymentInstallment::whereBetween('created_at', [$start, $end])->sum('applied_amount');
                $totalPayments = Payment::whereBetween('created_at', [$start, $end])->sum('amount');
                $profit = $totalPayments - $totalCapitalPaid;
            } elseif ($role === 2) { // Empresa
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }
                $companyId = $user->company->id;
                $sellerIds = Seller::where('company_id', $companyId)->pluck('id');
                $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                    $query->where('company_id', $companyId);
                })->pluck('id');
                $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');
                $installmentIds = Installment::whereIn('credit_id', $creditIds)->pluck('id');

                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('value');
                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('value');
                $collected = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');
                $newCredits = Credit::whereIn('seller_id', $sellerIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('credit_value');
                // $policy = Policy::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$start, $end])->sum('value');
                $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', $installmentIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('applied_amount');

                $totalPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');
                $profit = $totalPayments - $totalCapitalPaid;
            } elseif ($role === 5) { // Vendedor
                $seller = $user->seller;
                if (!$seller) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene vendedor asociado'
                    ]);
                }

                $income = Income::where('user_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('value');
                $expenses = Expense::where('user_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('value');

                // Obtener los créditos de ese vendedor
                $creditIds = Credit::where('seller_id', $seller->id)->pluck('id');
                $installmentIds = Installment::whereIn('credit_id', $creditIds)->pluck('id');

                // Filtrar los pagos por esos créditos
                $collected = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');

                $newCredits = Credit::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('credit_value');

                $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', $installmentIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('applied_amount');

                $totalPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount');

                $profit = $totalPayments - $totalCapitalPaid;
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'income' => (float) $income,
                    'expenses'  => (float) $expenses,
                    'collected' => (float) $collected,
                    'newCredits' => (float) $newCredits,
                    'policy' => (float) $policy,
                    'profit' => (float) $profit
                ],
                'period' => [
                    'start' => $start,
                    'end' => $end,
                    'filter' => $filter
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error("Error loading movements: " . $e->getMessage());
            return $this->errorResponse('Error al obtener movimientos.', 500);
        }
    }
}
