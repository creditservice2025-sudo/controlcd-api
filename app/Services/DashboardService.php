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
                'credits' => function ($query) use ($todayDate) {
                    $query->where(function ($q) use ($todayDate) {
                        // Incluir: créditos no excluidos
                        $q->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                            // O los excluidos pero con pagos HOY
                            ->orWhere(function ($q2) use ($todayDate) {
                                $q2->whereIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                                    ->whereHas('payments', function ($subQuery) use ($todayDate) {
                                        $subQuery->whereDate('payment_date', $todayDate);
                                    });
                            });
                    });
                },
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
            ])
                ->whereHas('credits', function ($query) use ($todayDate) {
                    $query->where(function ($q) use ($todayDate) {
                        $q->whereNotIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                            ->orWhere(function ($q2) use ($todayDate) {
                                $q2->whereIn('status', ['Cartera Irrecuperable', 'Liquidado'])
                                    ->whereHas('payments', function ($subQuery) use ($todayDate) {
                                        $subQuery->whereDate('payment_date', $todayDate);
                                    });
                            });
                    });
                })
                ->orderBy('created_at', 'desc');

            if ($role === 1) {
                // Admin: no filter
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

                // Initialize ENGLISH keys
                $sellerData = [
                    'id' => $seller->id,
                    'route' => $seller->name,
                    'location' => $location,
                    'initial_portfolio' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'to_collect' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'collected' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'pending' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'credits_today' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'collected_today' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'current_cash' => 0,
                    'previous_cash' => 0,
                    'capital' => 0,
                    'utility' => 0,
                    'credits' => 0,
                    'name' => $seller->user ? $seller->user->name : 'No name',
                    'total' => 0,
                    'paid_credits' => 0,
                    'unpaid_credits' => 0,
                    'paid_capital' => 0,
                    'paid_utility' => 0,
                    'irrecoverable_credits' => 0,
                    'irrecoverable_total' => 0,
                    'total_credits_value' => 0,
                    'total_paid_value' => 0,
                    'pending_value' => 0,
                    'gross_capital' => 0,
                    'gross_utility' => 0,
                ];

                foreach ($seller->credits as $credit) {
                    if ($credit->status === 'Cartera Irrecuperable') {
                        $sellerData['irrecoverable_credits']++;
                        $sellerData['irrecoverable_total'] += $credit->credit_value + ($credit->credit_value * $credit->total_interest / 100);
                        continue;
                    }

                    // --- Inicial ---
                    $capitalInitial = $credit->credit_value;
                    $utilityInitial = $credit->credit_value * $credit->total_interest / 100;
                    $totalInitial = $capitalInitial + $utilityInitial;

                    // Proporción por pago
                    $percentageCapital = $capitalInitial / $totalInitial;
                    $percentageUtility = $utilityInitial / $totalInitial;

                    // --- Pagos ---
                    $allPayments = $credit->payments;
                    $capitalPaid = 0;
                    $utilityPaid = 0;

                    foreach ($allPayments as $payment) {
                        $amount = $payment->amount ?? 0;
                        $capitalPaid += $amount * $percentageCapital;
                        $utilityPaid += $amount * $percentageUtility;
                    }

                    $capitalPending = max(0, $capitalInitial - $capitalPaid);
                    $utilityPending = max(0, $utilityInitial - $utilityPaid);

                    // --- Sumar a los totales del seller ---
                    $sellerData['initial_portfolio']['C'] += $capitalInitial;
                    $sellerData['initial_portfolio']['U'] += $utilityInitial;
                    $sellerData['initial_portfolio']['T'] += $totalInitial;

                    $sellerData['collected']['C'] += $capitalPaid;
                    $sellerData['collected']['U'] += $utilityPaid;
                    $sellerData['collected']['T'] += $capitalPaid + $utilityPaid;

                    $sellerData['to_collect']['C'] += $capitalPending;
                    $sellerData['to_collect']['U'] += $utilityPending;
                    $sellerData['to_collect']['T'] += $capitalPending + $utilityPending;

                    $sellerData['pending']['C'] += $capitalPending;
                    $sellerData['pending']['U'] += $utilityPending;
                    $sellerData['pending']['T'] += $capitalPending + $utilityPending;

                    // Credits today
                    if (Carbon::parse($credit->created_at)->toDateString() == $todayDate) {
                        $sellerData['credits_today']['C'] += $capitalInitial;
                        $sellerData['credits_today']['U'] += $utilityInitial;
                        $sellerData['credits_today']['T'] += $totalInitial;
                    }

                    // Pagos antes de hoy
                    $paymentsBeforeToday = $credit->payments()
                        ->where('created_at', '<', $startUTC)
                        ->get();

                    $capitalPaidBeforeToday = 0;
                    $utilityPaidBeforeToday = 0;

                    foreach ($paymentsBeforeToday as $payment) {
                        $amount = $payment->amount ?? 0;
                        $capitalPaidBeforeToday += $amount * $percentageCapital;
                        $utilityPaidBeforeToday += $amount * $percentageUtility;
                    }

                    // Pagos de hoy
                    $paymentsToday = $credit->payments()
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->get();

                    $collectedTodayCapital = 0;
                    $collectedTodayUtility = 0;
                    $collectedTodayTotal = 0;

                    foreach ($paymentsToday as $payment) {
                        $amount = $payment->amount ?? 0;
                        $collectedTodayCapital += $amount * $percentageCapital;
                        $collectedTodayUtility += $amount * $percentageUtility;
                        $collectedTodayTotal += $amount;
                    }

                    $sellerData['collected_today']['C'] += $collectedTodayCapital;
                    $sellerData['collected_today']['U'] += $collectedTodayUtility;
                    $sellerData['collected_today']['T'] += $collectedTodayTotal;

                    // Otros campos
                    $sellerData['credits']++;
                    if ($capitalPending == 0 && $utilityPending == 0) {
                        $sellerData['paid_credits']++;
                    } else {
                        $sellerData['unpaid_credits']++;
                    }

                    $sellerData['paid_capital'] += $capitalPaid;
                    $sellerData['paid_utility'] += $utilityPaid;

                    $sellerData['total_credits_value'] += $totalInitial;
                    $sellerData['total_paid_value'] += ($capitalPaid + $utilityPaid);
                    $sellerData['gross_capital'] += $capitalInitial;
                    $sellerData['gross_utility'] += $utilityInitial;
                }
                $sellerData['pending_value'] = max(0, $sellerData['initial_portfolio']['T'] - $sellerData['collected']['T']);
                // --- Current Cash Calculation ---
                $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->orderBy('date', 'desc')
                    ->first();

                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $lastLiquidationPrevious = Liquidation::where('seller_id', $seller->id)
                    ->where('date', '<', $todayDate)
                    ->orderBy('date', 'desc')
                    ->first();
                $previousCash = $lastLiquidationPrevious ? $lastLiquidationPrevious->real_to_deliver : 0;

                $creditIds = collect($seller->credits)->pluck('id')->toArray();
                \Log::debug("creditIds para seller {$seller->id}: " . json_encode($creditIds));

                $cashPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->sum('amount');
                \Log::debug("cashPayments para seller {$seller->id} entre $startUTC y $endUTC: $cashPayments");

                $expenses = Expense::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->where('status', 'Aprobado')
                    ->sum('value');
                \Log::debug("expenses para user {$seller->user_id} entre $startUTC y $endUTC (status Aprobado): $expenses");

                $income = Income::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->sum('value');
                \Log::debug("income para user {$seller->user_id} entre $startUTC y $endUTC: $income");

                $newCredits = Credit::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->whereNull('renewed_from_id')
                    ->sum('credit_value');
                \Log::debug("newCredits para seller {$seller->id} entre $startUTC y $endUTC: $newCredits");

                $renewalCredits = Credit::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->whereNotNull('renewed_from_id')
                    ->get();

                $total_renewal_disbursed = 0;


                foreach ($renewalCredits as $renewCredit) {
                    $oldCredit = Credit::find($renewCredit->renewed_from_id);

                    $pendingAmount = 0;
                    if ($oldCredit) {
                        $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                        $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                    }

                    $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                    $total_renewal_disbursed += $netDisbursement;
                }
                \Log::debug("total_renewal_disbursed para seller {$seller->id} entre $startUTC y $endUTC: $total_renewal_disbursed");


                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $todayDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');
                \Log::debug("irrecoverableCredits para seller {$seller->id} el $todayDate (Pendiente): $irrecoverableCredits");

                $currentCash =  $previousCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                \Log::debug("currentCash: previousCash($previousCash) + (income($income) + cashPayments($cashPayments)) - (expenses($expenses) + newCredits($newCredits) + total_renewal_disbursed($total_renewal_disbursed) + irrecoverableCredits($irrecoverableCredits)) = $currentCash");
                $sellerData['current_cash'] = (float) number_format($currentCash, 2, '.', '');
                $sellerData['previous_cash'] = (float) number_format($previousCash, 2, '.', '');
                $sellerData['income_today'] = (float) number_format($income, 2, '.', '');
                $sellerData['expenses_today'] = (float) number_format($expenses, 2, '.', '');
                $sellerData['cash_payments_today'] = (float) number_format($cashPayments, 2, '.', '');
                $sellerData['new_credits_today'] = (float) number_format($newCredits, 2, '.', '');
                $sellerData['irrecoverable_credits_today'] = (float) number_format($irrecoverableCredits, 2, '.', '');

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
                'message' => 'Error fetching pending portfolios'
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
            $newCredits = 0;
            $total_renewal_disbursed = 0;

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

                $lastLiquidation = Liquidation::orderBy('date', 'desc')
                    ->where('date', '<', $todayDate)
                    ->first();

                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $cashPayments = Payment::whereBetween('created_at', [$startUTC, $endUTC])->sum('amount');
                $expenses = Expense::whereBetween('created_at', [$startUTC, $endUTC])->sum('value');
                $income = Income::whereBetween('created_at', [$startUTC, $endUTC])->sum('value');
                $newCredits = Credit::whereBetween('created_at', [$startUTC, $endUTC])->whereNull('renewed_from_id')->sum('credit_value');

                // Créditos renovados del día (administrador)
                $renewalCredits = Credit::whereBetween('created_at', [$startUTC, $endUTC])
                    ->whereNotNull('renewed_from_id')
                    ->get();

                $total_renewal_disbursed = $renewalCredits->sum(function ($renewCredit) {
                    $oldCredit = Credit::find($renewCredit->renewed_from_id);
                    $pendingAmount = 0;
                    if ($oldCredit) {
                        $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                        $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                    }
                    return $renewCredit->credit_value - $pendingAmount;
                });

                $dailyPolicy = Credit::whereBetween('created_at', [$startUTC, $endUTC])
                    ->get()
                    ->sum(function ($credit) {
                        return $credit->credit_value * ($credit->micro_insurance_percentage ?? 0);
                    });

                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $todayDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                \Log::debug("=== INICIO cálculo de caja actual (currentCash) ===");
                \Log::debug("initialCash: {$initialCash}");
                \Log::debug("income: {$income}");
                \Log::debug("cashPayments: {$cashPayments}");
                \Log::debug("expenses: {$expenses}");
                \Log::debug("newCredits: {$newCredits}");
                \Log::debug("total_renewal_disbursed: {$total_renewal_disbursed}");
                \Log::debug("irrecoverableCredits: {$irrecoverableCredits}");
                \Log::debug("Fórmula: initialCash({$initialCash}) + (income({$income}) + cashPayments({$cashPayments})) - (expenses({$expenses}) + newCredits({$newCredits}) + total_renewal_disbursed({$total_renewal_disbursed}) + irrecoverableCredits({$irrecoverableCredits}))");

                $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                \Log::debug("currentCash: {$currentCash}");
                \Log::debug("=== FIN cálculo de caja actual (currentCash) ===");
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
                    $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');

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
                    $todayDate = Carbon::now($timezone)->toDateString();
                    $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                        ->orderBy('date', 'desc')
                        ->where('date', '<', $todayDate)
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
                        ->whereNull('renewed_from_id')
                        ->sum('credit_value');

                    // Créditos renovados del día (empresa)
                    $renewalCredits = Credit::whereIn('seller_id', $sellerIds)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->whereNotNull('renewed_from_id')
                        ->get();

                    $total_renewal_disbursed = $renewalCredits->sum(function ($renewCredit) {
                        $oldCredit = Credit::find($renewCredit->renewed_from_id);
                        $pendingAmount = 0;
                        if ($oldCredit) {
                            $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                            $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                            $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                        }
                        return $renewCredit->credit_value - $pendingAmount;
                    });

                    $dailyPolicy = Credit::whereIn('seller_id', $sellerIds)
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->get()
                        ->sum(function ($credit) {
                            return $credit->credit_value * ($credit->micro_insurance_percentage ?? 0);
                        });

                    $todayDate = Carbon::now($timezone)->toDateString();

                    $irrecoverableCredits = DB::table('installments')
                        ->join('credits', 'installments.credit_id', '=', 'credits.id')
                        ->whereIn('credits.seller_id', $sellerIds)
                        ->where('credits.status', 'Cartera Irrecuperable')
                        ->whereDate('credits.updated_at', $todayDate)
                        ->where('installments.status', 'Pendiente')
                        ->sum('installments.quota_amount');

                    \Log::debug("=== INICIO cálculo de caja actual (currentCash) ===");
                    \Log::debug("initialCash: {$initialCash}");
                    \Log::debug("income: {$income}");
                    \Log::debug("cashPayments: {$cashPayments}");
                    \Log::debug("expenses: {$expenses}");
                    \Log::debug("newCredits: {$newCredits}");
                    \Log::debug("total_renewal_disbursed: {$total_renewal_disbursed}");
                    \Log::debug("irrecoverableCredits: {$irrecoverableCredits}");
                    \Log::debug("Fórmula: initialCash({$initialCash}) + (income({$income}) + cashPayments({$cashPayments})) - (expenses({$expenses}) + newCredits({$newCredits}) + total_renewal_disbursed({$total_renewal_disbursed}) + irrecoverableCredits({$irrecoverableCredits}))");

                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                    \Log::debug("currentCash: {$currentCash}");
                    \Log::debug("=== FIN cálculo de caja actual (currentCash) ===");
                }
            } elseif ($role === 5) {
                $seller = $user->seller;
                if ($seller) {

                    $creditIds = $seller->credits()->pluck('id');

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

                    $todayDate = Carbon::now($timezone)->toDateString();

                    $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                        ->orderBy('date', 'desc')
                        ->where('date', '<', $todayDate)
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
                        ->whereNull('renewed_from_id')
                        ->sum('credit_value');

                    // Créditos renovados del día (vendedor)
                    $renewalCredits = $seller->credits()
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->whereNotNull('renewed_from_id')
                        ->get();

                    $total_renewal_disbursed = $renewalCredits->sum(function ($renewCredit) {
                        $oldCredit = Credit::find($renewCredit->renewed_from_id);
                        $pendingAmount = 0;
                        if ($oldCredit) {
                            $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                            $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                            $pendingAmount = $oldCreditTotal - $oldCreditPaid;
                        }
                        return $renewCredit->credit_value - $pendingAmount;
                    });

                    $dailyPolicy = $seller->credits()
                        ->whereBetween('created_at', [$startUTC, $endUTC])
                        ->get()
                        ->sum(function ($credit) {
                            return $credit->credit_value * ($credit->micro_insurance_percentage ?? 0);
                        });

                    $todayDate = Carbon::now($timezone)->toDateString();

                    $irrecoverableCredits = DB::table('installments')
                        ->join('credits', 'installments.credit_id', '=', 'credits.id')
                        ->where('credits.seller_id', $seller->id)
                        ->where('credits.status', 'Cartera Irrecuperable')
                        ->whereDate('credits.updated_at', $todayDate)
                        ->where('installments.status', 'Pendiente')
                        ->sum('installments.quota_amount');

                    \Log::debug("=== INICIO cálculo de caja actual (currentCash) ===");
                    \Log::debug("initialCash: {$initialCash}");
                    \Log::debug("income: {$income}");
                    \Log::debug("cashPayments: {$cashPayments}");
                    \Log::debug("expenses: {$expenses}");
                    \Log::debug("newCredits: {$newCredits}");
                    \Log::debug("total_renewal_disbursed: {$total_renewal_disbursed}");
                    \Log::debug("irrecoverableCredits: {$irrecoverableCredits}");
                    \Log::debug("Fórmula: initialCash({$initialCash}) + (income({$income}) + cashPayments({$cashPayments})) - (expenses({$expenses}) + newCredits({$newCredits}) + total_renewal_disbursed({$total_renewal_disbursed}) + irrecoverableCredits({$irrecoverableCredits}))");

                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                    \Log::debug("currentCash: {$currentCash}");
                    \Log::debug("=== FIN cálculo de caja actual (currentCash) ===");
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
                    'expenses' => (float) number_format($expenseTotal, 2, '.', ''),
                    'newCredits' => (float) number_format($newCredits, 2, '.', ''),
                    'renewalCreditsDisbursed' => (float) number_format($total_renewal_disbursed, 2, '.', ''),
                    'dailyPolicy' => (float) number_format($dailyPolicy, 2, '.', ''),
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
                $newCredits = Credit::whereBetween('created_at', [$startOfWeek, $endOfWeek])->whereNull('renewed_from_id')->sum('credit_value');
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
                    ->whereNull('renewed_from_id')
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
                    ->whereNull('renewed_from_id')
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
                $newCredits = Credit::whereBetween('created_at', [$start, $end])->whereNull('renewed_from_id')->sum('credit_value');
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
                    ->whereNull('renewed_from_id')
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
                    ->whereNull('renewed_from_id')
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
