<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Liquidation;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Seller;
use App\Traits\ApiResponse;
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

        $sellersQuery = Seller::with([
            'clients',
            'credits',
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

        foreach ($sellers as $seller) {
            $location = $this->getSellerLocation($seller);

            $sellerData = [
                'id' => $seller->id,
                'route' => $seller->name,
                'location' => $location,
                'capital' => 0,
                'utility' => 0,
                'credits' => count($seller->credits),
                'name' => $seller->user ? $seller->user->name : 'Sin nombre',
                'total' => 0
            ];

            foreach ($seller->credits as $credit) {
                $totalPaid = Payment::where('credit_id', $credit->id)
                    ->sum('amount');

                $utility = $credit->credit_value  * $credit->total_interest / 100;
                $capitalPayable = $credit->credit_value + $utility - $totalPaid;
                $totalPortfolio = $capitalPayable + $utility;

                $sellerData['capital'] += $capitalPayable;
                $sellerData['utility'] += $utility;
                $sellerData['total'] += $totalPortfolio;
            }

            $sellerData['capital'] = '$ ' . number_format($sellerData['capital'], 2, ',', '.');
            $sellerData['utility'] = '$ ' . number_format($sellerData['utility'], 2, ',', '.');
            $sellerData['total'] = '$ ' . number_format($sellerData['total'], 2, ',', '.');

            $result[] = $sellerData;
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    } catch (\Exception $e) {
        \Log::error("Error fetching pending portfolios: " . $e->getMessage());
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
            $today = now()->toDateString();

            $totalBalance = 0;
            $capitalPending = 0;
            $profitPending = 0;
            $currentCash = 0;
            $incomeTotal = 0;
            $expenseTotal = 0;

            if ($role === 1) {
                $creditIds = Credit::where('status', '!=', 'Liquidado')->pluck('id');

                if ($creditIds->isNotEmpty()) {
                    $totalBalance = Credit::whereIn('id', $creditIds)->sum('credit_value');
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

                $lastLiquidation = Liquidation::orderBy('date', 'desc')->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;
                $cashPayments = Payment::whereDate('created_at', $today)->sum('amount');
                $expenses = Expense::whereDate('created_at', $today)->sum('value');
                $income = Income::whereDate('created_at', $today)->sum('value');
                $newCredits = Credit::whereDate('created_at', $today)->sum('credit_value');
                $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits);
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
                        ->where('status', '!=', 'Liquidado')
                        ->pluck('id');

                    if ($creditIds->isNotEmpty()) {
                        $totalBalance = Credit::whereIn('id', $creditIds)->sum('credit_value');

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
                        ->whereDate('created_at', $today)
                        ->sum('amount');
                    $expenses = Expense::whereIn('user_id', $userIds)
                        ->whereDate('created_at', $today)
                        ->where('status', 'Aprobado')
                        ->sum('value');
                    $income = Income::whereIn('user_id', $userIds)
                        ->whereDate('created_at', $today)
                        ->sum('value');
                    $newCredits = Credit::whereIn('seller_id', $sellerIds)
                        ->whereDate('created_at', $today)
                        ->sum('credit_value');
                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits);
                }
            } elseif ($role === 5) {
                $seller = $user->seller;
                if ($seller) {
                    $creditIds = $seller->credits()
                        ->where('status', '!=', 'Liquidado')
                        ->pluck('id');

                    if ($creditIds->isNotEmpty()) {
                        $totalBalance = Credit::whereIn('id', $creditIds)->sum('credit_value');

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
                        ->whereDate('created_at', $today)
                        ->sum('amount');
                    $expenses = Expense::where('user_id', $user->id)
                        ->whereDate('created_at', $today)
                        ->where('status', 'Aprobado')
                        ->sum('value');
                    $income = Income::where('user_id', $user->id)
                        ->whereDate('created_at', $today)
                        ->sum('value');
                    $newCredits = $seller->credits()
                        ->whereDate('created_at', $today)
                        ->sum('credit_value');
                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits);
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
   public function weeklyMovements(Request $request)
{
    try {
        $user = Auth::user();
        $role = $user->role_id;

        $startOfWeek = now()->startOfWeek()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();

        $income = 0;
        $expenses = 0;

        if ($role === 1) {
            $income = Income::whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->sum('value');

            $expenses = Expense::whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->sum('value');
        } elseif ($role === 2) {
            if (!$user->company) {
                return $this->successResponse([
                    'success' => true,
                    'income' => 0,
                    'expenses' => 0,
                    'message' => 'Usuario no tiene compañía asociada'
                ]);
            }
            
            $companyId = $user->company->id;
            $userIds = User::whereHas('seller', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })->pluck('id');

            if ($userIds->isNotEmpty()) {
                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');

                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');
            }
        } elseif ($role === 5) {
            // Vendedor
            $income = Income::where('user_id', $user->id)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->sum('value');

            $expenses = Expense::where('user_id', $user->id)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->sum('value');
        }

        return $this->successResponse([
            'success' => true,
            'income' => (float) $income,
            'expenses' => (float) $expenses
        ]);
    } catch (\Exception $e) {
        \Log::error("Error loading weekly movements: " . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener movimientos semanales'
        ], 500);
    }
}
}
