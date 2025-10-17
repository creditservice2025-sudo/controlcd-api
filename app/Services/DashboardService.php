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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    use ApiResponse;

    protected function applyLocationFilters(Builder $query, Request $request): Builder
    {
        $countryId = $request->input('country_id');
        $cityId = $request->input('city_id');

        if ($cityId) {
            $query->where('city_id', $cityId);
        } elseif ($countryId) {
            $query->whereHas('city', function ($q) use ($countryId) {
                $q->where('country_id', $countryId);
            });
        }

        return $query;
    }

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

            // ------------------------------------------------------------------
            // ROL 1 (ADMIN - Global con filtros de ubicación)
            // ------------------------------------------------------------------
            if ($role === 1) {
                // MIEMBROS: Usuarios filtrados por ubicación a través de su Vendedor (Seller)
                $data['members'] = User::whereHas('seller', function ($query) use ($request) {
                    // Aplicamos filtros de ubicación al Vendedor asociado al Usuario
                    $this->applyLocationFilters($query, $request);
                })->count();

                // RUTAS/VENDEDORES: Vendedores filtrados por su ubicación
                $routesQuery = Seller::query();
                $data['routes'] = $this->applyLocationFilters($routesQuery, $request)->count();

                // CRÉDITOS & CLIENTES: Obtenemos los IDs de los vendedores filtrados por ubicación
                $sellerIdsQuery = Seller::query();
                $sellerIds = $this->applyLocationFilters($sellerIdsQuery, $request)->pluck('id');

                // Aplicamos los filtros de vendedor (que ya llevan la ubicación) a Créditos y Clientes
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)->count();


                // ------------------------------------------------------------------
                // ROL 2 (COMPAÑÍA - Filtrado por Compañía + Filtros de Ubicación)
                // ------------------------------------------------------------------
            } elseif ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => $data,
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }

                $companyId = $user->company->id;

                // 1. Obtener IDs de vendedores filtrados por Compañía Y Ubicación
                $sellerIdsQuery = Seller::where('company_id', $companyId);
                $sellerIds = $this->applyLocationFilters($sellerIdsQuery, $request)->pluck('id');

                // RUTAS/VENDEDORES: Ya está contado en el paso anterior (contar el array de IDs)
                $data['routes'] = count($sellerIds);

                // MIEMBROS: Usuarios filtrados por la compañía del vendedor Y ubicación
                $data['members'] = User::whereHas('seller', function ($query) use ($companyId, $request) {
                    $query->where('company_id', $companyId);
                    $this->applyLocationFilters($query, $request);
                })->count();

                // CRÉDITOS Y CLIENTES: Usamos el array de IDs de vendedores filtrados
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)->count();


                // ------------------------------------------------------------------
                // ROL 5 (VENDEDOR - Filtrado solo por Vendedor, ubicación no aplica)
                // ------------------------------------------------------------------
            } elseif ($role === 5) {
                // Se mantiene igual, ya está filtrado por el vendedor logueado
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

    public function loadPendingPortfolios(Request $request)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $timezone  = 'America/Caracas';
            $startUTC  = Carbon::now($timezone)->startOfDay()->timezone('UTC');
            $endUTC    = Carbon::now($timezone)->endOfDay()->timezone('UTC');
            $todayDate = Carbon::now($timezone)->toDateString();

            $sellersQuery = Seller::with([
                'credits' => function ($query) use ($todayDate) {
                    $query->whereNull('deleted_at');
                },
                'credits.installments.payments',
                'city:id,name,country_id',
                'city.country:id,name',
                'user:id,name',
            ])
                ->whereHas('credits', function ($query) use ($todayDate) {
                    $query->whereNull('deleted_at');
                })
                ->orderBy('created_at', 'desc');

            if ($role === 2 && !$user->company) {
                return response()->json(['success' => true, 'data' => []]);
            }
            if ($role === 2) $sellersQuery->where('company_id', $user->company->id);
            if ($role === 5) $sellersQuery->where('user_id', $user->id);
            if (!in_array($role, [1, 2, 5])) {
                return response()->json(['success' => true, 'data' => []]);
            }

            if (in_array($role, [1, 2])) {
                $this->applyLocationFilters($sellersQuery, $request);
            }

            $sellers = $sellersQuery->take(10)->get();
            $result = [];

            foreach ($sellers as $seller) {
                $location = $this->getSellerLocation($seller);
                $sellerData = [
                    'id' => $seller->id,
                    'route' => $seller->name,
                    'name' => $seller->user ? $seller->user->name : 'No name',
                    'location' => $location,
                    'initial_portfolio' => ['T' => 0, 'C' => 0, 'U' => 0],
                    'collected'         => ['T' => 0, 'C' => 0, 'U' => 0],
                    'to_collect'        => ['T' => 0, 'C' => 0, 'U' => 0],
                    'credits_today'     => ['T' => 0, 'C' => 0, 'U' => 0],
                    'collected_today'   => ['T' => 0, 'C' => 0, 'U' => 0],
                    'previous_cash'     => 0,
                    'current_cash'      => 0,
                    'utility_collected_today' => 0,
                ];

                $creditsActivos = $seller->credits->filter(function ($credit) {
                    return $credit->status !== 'Cartera Irrecuperable';
                });


                foreach ($creditsActivos as $credit) {
                    $capitalInitial   = $credit->credit_value;
                    $utilityInitial   = $credit->credit_value * $credit->total_interest / 100;
                    $totalInitial     = $capitalInitial + $utilityInitial;

                    $percentageCapital = $totalInitial > 0 ? $capitalInitial / $totalInitial : 0;
                    $percentageUtility = $totalInitial > 0 ? $utilityInitial / $totalInitial : 0;

                    $capitalPagado = 0;
                    $utilityPagado = 0;

                    $totalPagado = $credit->payments->sum('amount');
                   /*  foreach ($credit->installments ?? [] as $installment) {
                        if ($installment->status == 'Pagado') {
                            $quotaAmount = $installment->quota_amount ?? 0;
                            $capitalPagado += $quotaAmount * $percentageCapital;
                            $utilityPagado += $quotaAmount * $percentageUtility;
                        }
                    } */

                    $capitalPagado = $totalPagado * $percentageCapital;
                $utilityPagado = $totalPagado * $percentageUtility;

                $capitalPendiente = $capitalInitial - $capitalPagado;
                $utilityPendiente = $utilityInitial - $utilityPagado;
                $totalPendiente   = $capitalPendiente + $utilityPendiente;

                    $capitalPendiente = max(0, $capitalPendiente);
                $utilityPendiente = max(0, $utilityPendiente);
                $totalPendiente   = max(0, $totalPendiente);

                    $sellerData['to_collect']['C'] += $capitalPendiente;
                    $sellerData['to_collect']['U'] += $utilityPendiente;
                    $sellerData['to_collect']['T'] += $totalPendiente;

                    $sellerData['initial_portfolio']['C'] += $capitalInitial;
                    $sellerData['initial_portfolio']['U'] += $utilityInitial;
                    $sellerData['initial_portfolio']['T'] += $totalInitial;
                }

                foreach ($seller->credits as $credit) {
                    $isIrrecuperable = $credit->status === 'Cartera Irrecuperable';

                    $capitalInitial   = $credit->credit_value;
                    $utilityInitial   = $credit->credit_value * $credit->total_interest / 100;
                    $totalInitial     = $capitalInitial + $utilityInitial;

                    $percentageCapital = $totalInitial > 0 ? $capitalInitial / $totalInitial : 0;
                    $percentageUtility = $totalInitial > 0 ? $utilityInitial / $totalInitial : 0;

                    $capitalPaid = 0;
                    $utilityPaid = 0;
                    $totalPaid = 0;
                /*     foreach ($credit->installments ?? [] as $installment) {
                        if ($installment->status == 'Pagado') {
                            $quotaAmount = $installment->quota_amount ?? 0;
                            $totalPaid += $installment->quota_amount ?? 0;
                            $capitalPaid += $quotaAmount * $percentageCapital;
                            $utilityPaid += $quotaAmount * $percentageUtility;
                        }
                    } */

                    $totalPaid = $credit->payments->sum('amount'); // Suma total pagada
                    $capitalPaid = $totalPaid * $percentageCapital;
                    $utilityPaid = $totalPaid * $percentageUtility;


                    $sellerData['collected']['C'] += $capitalPaid;
                    $sellerData['collected']['U'] += $utilityPaid;
                    $sellerData['collected']['T'] += $totalPaid;

                    if (
                        Carbon::parse($credit->created_at)->toDateString() == $todayDate
                        && $credit->renewed_from_id === null

                    ) {
                        $sellerData['credits_today']['C'] += $capitalInitial;
                        $sellerData['credits_today']['U'] += $utilityInitial;
                        $sellerData['credits_today']['T'] += $totalInitial;
                    }



                    // 3. Inicial y pendiente (solo si NO es cartera irrecuperable)
                    /*  if (!$isIrrecuperable) {
                        // Pendiente
                        $capitalPagado = 0;
                        $utilityPagado = 0;
                        foreach ($credit->installments ?? [] as $installment) {
                            if ($installment->status == 'Pagado') {
                                $quotaAmount = $installment->quota_amount ?? 0;
                                $capitalPagado += $quotaAmount * $percentageCapital;
                                $utilityPagado += $quotaAmount * $percentageUtility;
                            }
                        }
                        $capitalPendiente = $capitalInitial - $capitalPagado;
                        $utilityPendiente = $utilityInitial - $utilityPagado;
                        $totalPendiente = $capitalPendiente + $utilityPendiente;
                
                        $sellerData['to_collect']['C'] += $capitalPendiente;
                        $sellerData['to_collect']['U'] += $utilityPendiente;
                        $sellerData['to_collect']['T'] += $totalPendiente;
                
                        // Inicial
                        $sellerData['initial_portfolio']['C'] += $capitalInitial;
                        $sellerData['initial_portfolio']['U'] += $utilityInitial;
                        $sellerData['initial_portfolio']['T'] += $totalInitial;
                    } */

                    // 4. Pagos de hoy (de todos los créditos)
                    $paymentsToday = $credit->payments()->whereBetween('created_at', [$startUTC, $endUTC])->get();
                    

                    $collectedTodayTotal = $paymentsToday->sum('amount'); 
                    $collectedTodayCapital = $collectedTodayTotal * $percentageCapital;
                    $collectedTodayUtility = $collectedTodayTotal * $percentageUtility;

                    /* foreach ($paymentsToday as $payment) {
                        $amount = $payment->amount ?? 0;
                        $collectedTodayCapital += $amount * $percentageCapital;
                        $collectedTodayUtility += $amount * $percentageUtility;
                        $collectedTodayTotal   += $amount;
                    } */
                    $sellerData['collected_today']['C'] += $collectedTodayCapital;
                    $sellerData['collected_today']['U'] += $collectedTodayUtility;
                    $sellerData['collected_today']['T'] += $collectedTodayTotal;

                    $sellerData['utility_collected_today'] += $collectedTodayUtility;
                }



                $renewalCredits = DB::table('credits')
                    ->where('seller_id', $seller->id)
                    ->whereDate('created_at', $todayDate)
                    ->whereNotNull('renewed_from_id')
                    ->get();

                $total_renewal_disbursed = 0;
                $total_pending_absorbed = 0;

                foreach ($renewalCredits as $renewCredit) {
                    $oldCredit = DB::table('credits')->where('id', $renewCredit->renewed_from_id)->first();
                    $pendingAmount = 0;
                    if ($oldCredit) {
                        $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                        $oldCreditPaid  = DB::table('payments')->where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount  = $oldCreditTotal - $oldCreditPaid;
                        $total_pending_absorbed += $pendingAmount;
                    }
                    $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                    $total_renewal_disbursed += $netDisbursement;
                }

                $sellerData['total_renewal_disbursed'] = (float) number_format($total_renewal_disbursed, 2, '.', '');
                $sellerData['total_pending_absorbed'] = (float) number_format($total_pending_absorbed, 2, '.', '');

                // Caja inicial: última liquidación antes de hoy
                $lastApprovedLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->where('date', '<', $todayDate)
                    ->orderBy('date', 'desc')
                    ->first();

                $sellerData['previous_cash'] = $lastApprovedLiquidation
                    ? (float) number_format($lastApprovedLiquidation->real_to_deliver, 2, '.', '')
                    : 0;

                // Caja actual
                $initialCash = $lastApprovedLiquidation ? $lastApprovedLiquidation->real_to_deliver : 0;
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
                    ->whereNull('renewed_from_id')
                    ->sum('credit_value');
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
                        $oldCreditPaid  = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                        $pendingAmount  = $oldCreditTotal - $oldCreditPaid;
                    }
                    $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                    $total_renewal_disbursed += $netDisbursement;
                }
                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $todayDate)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');
                $currentCash =  $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                $sellerData['current_cash'] = (float) number_format($currentCash, 2, '.', '');

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


            $totalBalance = $capitalPending = $profitPending = $currentCash = 0;
            $incomeTotal = $expenseTotal = $newCredits = $total_renewal_disbursed = 0;
            $cashDayBalance = 0;
            $dailyPolicy = 0;
            $irrecoverableCredits = 0;
            $initialCash = 0;

            // ------------------------------------------------------------------
            // ROL 1 (ADMIN - Global con filtros de ubicación)
            // ------------------------------------------------------------------
            if ($role === 1) {
                // 1. Obtener IDs de créditos filtrados por ubicación (a través del Seller)
                $creditIdsQuery = Credit::query();
                if ($request->has(['country_id', 'city_id'])) {
                    // Si se pasa ubicación, filtramos los créditos que pertenecen a un vendedor en esa ubicación
                    $creditIdsQuery->whereHas('seller', function ($query) use ($request) {
                        $this->applyLocationFilters($query, $request);
                    });
                }
                $creditIds = $creditIdsQuery->pluck('id');

                // 2. Obtener IDs de usuarios (para Ingresos/Gastos)
                $userIds = User::pluck('id');
                // Si hay filtros de ubicación, filtramos los usuarios que tienen un vendedor en esa ubicación
                if ($request->has(['country_id', 'city_id'])) {
                    $userIds = User::whereHas('seller', function ($query) use ($request) {
                        $this->applyLocationFilters($query, $request);
                    })->pluck('id');
                }

                // CÁLCULOS PRINCIPALES DE CARTERA
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

                // CÁLCULOS GLOBALES DE INGRESOS/GASTOS (filtrados por usuario/vendedor)
                $incomeTotal = Income::whereIn('user_id', $userIds)->sum('value');
                $expenseTotal = Expense::whereIn('user_id', $userIds)->sum('value');

                // CÁLCULOS DE CAJA DEL DÍA

                // 1. Obtener IDs de vendedores filtrados para la liquidación
                $sellerIdsQuery = Seller::query();
                if ($request->has(['country_id', 'city_id'])) {
                    $this->applyLocationFilters($sellerIdsQuery, $request);
                }
                $sellerIds = $sellerIdsQuery->pluck('id');

                // 2. Última liquidación SUMADA de CADA VENDEDOR (CORRECCIÓN APLICADA AQUÍ)
                if ($sellerIds->isNotEmpty()) {
                    $sub = Liquidation::selectRaw('MAX(date) as max_date, seller_id')
                        ->whereIn('seller_id', $sellerIds)
                        ->where('date', '<', $today)
                        ->groupBy('seller_id');

                    $initialCash = Liquidation::query()
                        ->joinSub($sub, 'last_liquidations', function ($join) {
                            $join->on('liquidations.seller_id', '=', 'last_liquidations.seller_id')
                                ->on('liquidations.date', '=', 'last_liquidations.max_date');
                        })
                        ->whereIn('liquidations.seller_id', $sellerIds)
                        ->sum('real_to_deliver');
                } else {
                    $initialCash = 0;
                }
                // FIN DE CORRECCIÓN PARA $initialCash

                // 3. Flujos de caja del día
                $cashPayments = Payment::whereIn('credit_id', $creditIds)->whereBetween('created_at', [$startUTC, $endUTC])->sum('amount');
                $expenses = Expense::whereIn('user_id', $userIds)->whereBetween('created_at', [$startUTC, $endUTC])->sum('value');
                $income = Income::whereIn('user_id', $userIds)->whereBetween('created_at', [$startUTC, $endUTC])->sum('value');

                $newCredits = Credit::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$startUTC, $endUTC])->whereNull('renewed_from_id')->sum('credit_value');

                // 4. Renovaciones
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

                // 5. Política/Seguro
                $dailyPolicy = Credit::whereIn('seller_id', $sellerIds)
                    ->whereBetween('created_at', [$startUTC, $endUTC])
                    ->get()
                    ->sum(function ($credit) {
                        return ($credit->credit_value * ($credit->micro_insurance_percentage ?? 0) / 100);
                    });

                // 6. Cartera Irrecuperable
                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->whereIn('credits.seller_id', $sellerIds)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereDate('credits.updated_at', $today)
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                \Log::debug("ROL 1 [CASH-DEBUG] Inicio Caja");
                \Log::debug("ROL 1 [CASH-DEBUG] Filtros: Country: " . $request->input('country_id', 'N/A') . ", City: " . $request->input('city_id', 'N/A'));
                \Log::debug("ROL 1 [CASH-DEBUG] initialCash (última liquidación SUMADA): {$initialCash}"); // Log actualizado
                \Log::debug("ROL 1 [CASH-DEBUG] + Ingresos (Income): {$income}");
                \Log::debug("ROL 1 [CASH-DEBUG] + Cobros (Payments): {$cashPayments}");
                \Log::debug("ROL 1 [CASH-DEBUG] - Gastos (Expenses): {$expenses}");
                \Log::debug("ROL 1 [CASH-DEBUG] - Nuevos Créditos Desembolsados (New Credits): {$newCredits}");
                \Log::debug("ROL 1 [CASH-DEBUG] - Renovaciones Desembolsadas (Renewals): {$total_renewal_disbursed}");
                \Log::debug("ROL 1 [CASH-DEBUG] - Irrecuperable (Irrecoverable): {$irrecoverableCredits}");

                $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                $cashDayBalance = ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                \Log::debug("ROL 1 [CASH-DEBUG] cashDayBalance (Flujo del día): {$cashDayBalance}");
                \Log::debug("ROL 1 [CASH-DEBUG] currentCash (Caja Actual): {$currentCash}");
                \Log::debug("ROL 1 [CASH-DEBUG] Fin Caja");
            }

            // ------------------------------------------------------------------
            // ROL 2 (COMPAÑÍA - Filtrado por Compañía + Filtros de Ubicación)
            // ------------------------------------------------------------------
            elseif ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [
                            'totalBalance' => 0,
                            'capital' => 0,
                            'profit' => 0,
                            'currentCash' => 0,
                            'cashDayBalance' => 0,
                            'income' => 0,
                            'expenses' => 0
                        ],
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }

                $companyId = $user->company->id;

                // 1. Obtener IDs de vendedores filtrados por Compañía Y Ubicación
                $sellerIdsQuery = Seller::where('company_id', $companyId);
                $this->applyLocationFilters($sellerIdsQuery, $request); // Aplicar filtro de ubicación
                $sellerIds = $sellerIdsQuery->pluck('id');

                if ($sellerIds->isNotEmpty()) {
                    // 2. Obtener IDs de créditos para cálculos de cartera
                    $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');

                    if ($creditIds->isNotEmpty()) {
                        $totalBalance = Credit::whereIn('id', $creditIds)->sum(DB::raw('credit_value + (credit_value * total_interest / 100)'));
                        $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', function ($query) use ($creditIds) {
                            $query->select('id')->from('installments')->whereIn('credit_id', $creditIds);
                        })->sum('applied_amount');
                        $totalPayments = Payment::whereIn('credit_id', $creditIds)->sum('amount');
                        $totalProfitPaid = $totalPayments - $totalCapitalPaid;
                        $capitalPending = $totalBalance - $totalCapitalPaid;
                        $totalExpectedProfit = Credit::whereIn('id', $creditIds)->sum(DB::raw('credit_value * total_interest / 100'));
                        $profitPending = $totalExpectedProfit - $totalProfitPaid;
                    }

                    // 3. Obtener IDs de usuarios para Ingresos/Gastos
                    $userIds = User::whereHas('seller', function ($query) use ($sellerIds) {
                        $query->whereIn('id', $sellerIds);
                    })->pluck('id');

                    // CÁLCULOS GLOBALES DE INGRESOS/GASTOS (filtrados por Compañía y Ubicación)
                    $incomeTotal = Income::whereIn('user_id', $userIds)->sum('value');
                    $expenseTotal = Expense::whereIn('user_id', $userIds)->sum('value');

                    // CÁLCULOS DE CAJA DEL DÍA

                    // Última liquidación SUMADA de CADA VENDEDOR (CORRECCIÓN APLICADA AQUÍ)
                    $sub = Liquidation::selectRaw('MAX(date) as max_date, seller_id')
                        ->whereIn('seller_id', $sellerIds)
                        ->where('date', '<', $today)
                        ->groupBy('seller_id');

                    $initialCash = Liquidation::query()
                        ->joinSub($sub, 'last_liquidations', function ($join) {
                            $join->on('liquidations.seller_id', '=', 'last_liquidations.seller_id')
                                ->on('liquidations.date', '=', 'last_liquidations.max_date');
                        })
                        ->whereIn('liquidations.seller_id', $sellerIds)
                        ->sum('real_to_deliver');
                    // FIN DE CORRECCIÓN PARA $initialCash

                    // Flujos de caja del día (el resto del código se mantiene igual, usando $creditIds, $userIds, $sellerIds)
                    $cashPayments = Payment::whereIn('credit_id', $creditIds ?? [])->whereBetween('created_at', [$startUTC, $endUTC])->sum('amount');
                    $expenses = Expense::whereIn('user_id', $userIds)->whereBetween('created_at', [$startUTC, $endUTC])->where('status', 'Aprobado')->sum('value');
                    $income = Income::whereIn('user_id', $userIds)->whereBetween('created_at', [$startUTC, $endUTC])->sum('value');
                    $newCredits = Credit::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$startUTC, $endUTC])->whereNull('renewed_from_id')->sum('credit_value');

                    // Renovaciones
                    $renewalCredits = Credit::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$startUTC, $endUTC])->whereNotNull('renewed_from_id')->get();
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

                    // Política/Seguro
                    $dailyPolicy = Credit::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$startUTC, $endUTC])->get()->sum(function ($credit) {
                        return ($credit->credit_value * ($credit->micro_insurance_percentage ?? 0)) / 100;
                    });

                    // Cartera Irrecuperable
                    $irrecoverableCredits = DB::table('installments')
                        ->join('credits', 'installments.credit_id', '=', 'credits.id')
                        ->whereIn('credits.seller_id', $sellerIds)
                        ->where('credits.status', 'Cartera Irrecuperable')
                        ->whereDate('credits.updated_at', $today)
                        ->where('installments.status', 'Pendiente')
                        ->sum('installments.quota_amount');

                    // === LOGS AÑADIDOS PARA DEBUGGING ===
                    \Log::debug("ROL 2 [CASH-DEBUG] Inicio Caja");
                    \Log::debug("ROL 2 [CASH-DEBUG] Company ID: {$companyId}, Filtros: Country: " . $request->input('country_id', 'N/A') . ", City: " . $request->input('city_id', 'N/A'));
                    \Log::debug("ROL 2 [CASH-DEBUG] initialCash (última liquidación SUMADA): {$initialCash}"); // Log actualizado
                    \Log::debug("ROL 2 [CASH-DEBUG] + Ingresos (Income): {$income}");
                    \Log::debug("ROL 2 [CASH-DEBUG] + Cobros (Payments): {$cashPayments}");
                    \Log::debug("ROL 2 [CASH-DEBUG] - Gastos (Expenses): {$expenses}");
                    \Log::debug("ROL 2 [CASH-DEBUG] - Nuevos Créditos Desembolsados (New Credits): {$newCredits}");
                    \Log::debug("ROL 2 [CASH-DEBUG] - Renovaciones Desembolsadas (Renewals): {$total_renewal_disbursed}");
                    \Log::debug("ROL 2 [CASH-DEBUG] - Irrecuperable (Irrecoverable): {$irrecoverableCredits}");
                    // === FIN LOGS AÑADIDOS ===

                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                    $cashDayBalance = ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);

                    \Log::debug("ROL 2 [CASH-DEBUG] cashDayBalance (Flujo del día): {$cashDayBalance}");
                    \Log::debug("ROL 2 [CASH-DEBUG] currentCash (Caja Actual): {$currentCash}");
                    \Log::debug("ROL 2 [CASH-DEBUG] Fin Caja");
                }
            }

            // ------------------------------------------------------------------
            // ROL 5 (VENDEDOR - Filtrado por Vendedor, ubicación no aplica)
            // ------------------------------------------------------------------
            elseif ($role === 5) {
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

                    // Última liquidación del VENDEDOR (Este código es correcto para ROL 5 y se mantiene)
                    $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                        ->orderBy('date', 'desc')
                        ->where('date', '<', $todayDate)
                        ->first();
                    $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;
                    // Fin del código de $initialCash para ROL 5

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
                            return ($credit->credit_value * ($credit->micro_insurance_percentage ?? 0)) / 100;
                        });

                    $todayDate = Carbon::now($timezone)->toDateString();

                    $irrecoverableCredits = DB::table('installments')
                        ->join('credits', 'installments.credit_id', '=', 'credits.id')
                        ->where('credits.seller_id', $seller->id)
                        ->where('credits.status', 'Cartera Irrecuperable')
                        ->whereDate('credits.updated_at', $todayDate)
                        ->where('installments.status', 'Pendiente')
                        ->sum('installments.quota_amount');


                    \Log::debug("ROL 5 [CASH-DEBUG] Inicio Caja");
                    \Log::debug("ROL 5 [CASH-DEBUG] Seller ID: {$seller->id}");
                    \Log::debug("ROL 5 [CASH-DEBUG] initialCash (última liquidación): {$initialCash}");
                    \Log::debug("ROL 5 [CASH-DEBUG] + Ingresos (Income): {$income}");
                    \Log::debug("ROL 5 [CASH-DEBUG] + Cobros (Payments): {$cashPayments}");
                    \Log::debug("ROL 5 [CASH-DEBUG] - Gastos (Expenses): {$expenses}");
                    \Log::debug("ROL 5 [CASH-DEBUG] - Nuevos Créditos Desembolsados (New Credits): {$newCredits}");
                    \Log::debug("ROL 5 [CASH-DEBUG] - Renovaciones Desembolsadas (Renewals): {$total_renewal_disbursed}");
                    \Log::debug("ROL 5 [CASH-DEBUG] - Irrecuperable (Irrecoverable): {$irrecoverableCredits}");
                    $currentCash = $initialCash + ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                    $cashDayBalance = ($income + $cashPayments) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
                    \Log::debug("ROL 5 [CASH-DEBUG] cashDayBalance (Flujo del día): {$cashDayBalance}");
                    \Log::debug("ROL 5 [CASH-DEBUG] currentCash (Caja Actual): {$currentCash}");
                    \Log::debug("ROL 5 [CASH-DEBUG] Fin Caja");
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'totalBalance' => (float) number_format($totalBalance, 2, '.', ''),
                    'capital' => (float) number_format($capitalPending, 2, '.', ''),
                    'profit' => (float) number_format($profitPending, 2, '.', ''),
                    'currentCash' => (float) number_format($currentCash, 2, '.', ''),
                    'cashDayBalance' => (float) number_format($cashDayBalance, 2, '.', ''),
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

            // Define el rango de fechas UTC
            $startOfWeek = Carbon::now($timezone)->startOfWeek()->timezone('UTC');
            $endOfWeek = Carbon::now($timezone)->endOfWeek()->timezone('UTC');
            $startOfWeekDate = $startOfWeek->toDateString(); // Fecha de inicio de semana para liquidación

            // Inicialización de variables
            $initialCash = $income = $cashPayments = $newCredits = $expenses = $irrecoverableCredits = 0;

            // ------------------------------------------------------------------
            // ROL 1 (ADMIN - Global con filtros de ubicación)
            // ------------------------------------------------------------------
            if ($role === 1) {
                // 1. Obtener IDs de vendedores filtrados por ubicación
                $sellerIdsQuery = Seller::query();
                $this->applyLocationFilters($sellerIdsQuery, $request); // <-- Aplicar filtro de ubicación
                $sellerIds = $sellerIdsQuery->pluck('id');

                // 2. Obtener IDs de créditos y usuarios filtrados
                $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');
                $userIds = User::whereHas('seller', function ($query) use ($sellerIds) {
                    $query->whereIn('id', $sellerIds);
                })->pluck('id');

                // 3. Efectivo inicial (Liquidación al inicio de la semana)
                // Se busca la liquidación más antigua que coincida con el inicio de la semana para los vendedores filtrados.
                $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                    ->orderBy('date', 'asc')
                    ->whereDate('date', $startOfWeekDate)
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                // 4. Cálculos Semanales (aplicando los IDs filtrados)
                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');
                $cashPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('amount');
                $newCredits = Credit::whereIn('seller_id', $sellerIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->whereNull('renewed_from_id')->sum('credit_value');
                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');

                // 5. Cartera Irrecuperable
                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->whereIn('credits.seller_id', $sellerIds)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [$startOfWeek, $endOfWeek])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                // ------------------------------------------------------------------
                // ROL 2 (EMPRESA - Filtrado por Compañía + Filtros de Ubicación)
                // ------------------------------------------------------------------
            } elseif ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }
                $companyId = $user->company->id;

                // 1. Obtener IDs de vendedores filtrados por Compañía Y Ubicación
                $sellerIdsQuery = Seller::where('company_id', $companyId);
                $this->applyLocationFilters($sellerIdsQuery, $request); // <-- Aplicar filtro de ubicación
                $sellerIds = $sellerIdsQuery->pluck('id');

                // 2. Obtener IDs de créditos y usuarios filtrados
                $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');
                $userIds = User::whereHas('seller', function ($query) use ($sellerIds) {
                    $query->whereIn('id', $sellerIds);
                })->pluck('id');

                // Si no hay vendedores filtrados, los IDs serán vacíos y los sum serán 0.

                // 3. Efectivo inicial
                $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                    ->orderBy('date', 'asc')
                    ->whereDate('date', $startOfWeekDate)
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                // 4. Cálculos Semanales (usando los IDs filtrados por compañía/ubicación)
                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');
                $cashPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('amount');
                $newCredits = Credit::whereIn('seller_id', $sellerIds)
                    ->whereNull('renewed_from_id')
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('credit_value');
                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');

                // 5. Cartera Irrecuperable
                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->whereIn('credits.seller_id', $sellerIds)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [$startOfWeek, $endOfWeek])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');

                // ------------------------------------------------------------------
                // ROL 5 (VENDEDOR - Filtrado por Vendedor, ubicación no aplica)
                // ------------------------------------------------------------------
            } elseif ($role === 5) { // Vendedor
                $seller = $user->seller;
                if (!$seller) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene vendedor asociado'
                    ]);
                }
                $creditIds = $seller->credits()->pluck('id');

                // Efectivo inicial
                $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                    ->orderBy('date', 'asc')
                    ->whereDate('date', $startOfWeekDate)
                    ->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                // Cálculos Semanales (usando el ID de vendedor/usuario logueado)
                $income = Income::where('user_id', $user->id)->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');
                $cashPayments = Payment::whereIn('credit_id', $creditIds)->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('amount');
                $newCredits = Credit::where('seller_id', $seller->id)->whereNull('renewed_from_id')->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('credit_value');
                $expenses = Expense::where('user_id', $user->id)->whereBetween('created_at', [$startOfWeek, $endOfWeek])->sum('value');

                // Cartera Irrecuperable
                $irrecoverableCredits = DB::table('installments')
                    ->join('credits', 'installments.credit_id', '=', 'credits.id')
                    ->where('credits.seller_id', $seller->id)
                    ->where('credits.status', 'Cartera Irrecuperable')
                    ->whereBetween('credits.updated_at', [$startOfWeek, $endOfWeek])
                    ->where('installments.status', 'Pendiente')
                    ->sum('installments.quota_amount');
            }

            // Cálculos Finales
            $balanceGeneral = $initialCash + ($income + $cashPayments) - ($newCredits + $expenses + $irrecoverableCredits);
            $currentDayBalance = ($income + $cashPayments) - ($newCredits + $expenses + $irrecoverableCredits);

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'balanceGeneral' => (float) number_format($balanceGeneral, 2, '.', ''),
                    'currentDayBalance' => (float) number_format($currentDayBalance, 2, '.', ''),
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

            $filter = $request->input('filter');

            $start = Carbon::create(2000, 1, 1, 0, 0, 0, 'UTC');
            $end = Carbon::now($timezone)->addYears(10)->timezone('UTC');


            if ($filter === 'day') {
                $start = Carbon::now($timezone)->startOfDay()->timezone('UTC');
                $end = Carbon::now($timezone)->endOfDay()->timezone('UTC');
            } elseif ($filter === 'week') {
                $start = Carbon::now($timezone)->startOfWeek()->timezone('UTC');
                $end = Carbon::now($timezone)->endOfWeek()->timezone('UTC');
            } elseif ($filter === 'month') {
                $start = Carbon::now($timezone)->startOfMonth()->timezone('UTC');
                $end = Carbon::now($timezone)->endOfMonth()->timezone('UTC');
            }

            $income = 0;
            $expenses = 0;
            $collected = 0;
            $newCredits = 0;
            $policy = 0;
            $profit = 0;

            // ------------------------------------------------------------------
            // ROL 1 (ADMIN - Global con filtros de ubicación)
            // ------------------------------------------------------------------
            if ($role === 1) {
                // 1. Obtener IDs de vendedores filtrados por ubicación
                $sellerIdsQuery = Seller::query();
                // Si el filtro es 'all', se asume que se quiere ver la ubicación total de la empresa (sin filtro geográfico).
                // Mantenemos la aplicación de filtros si se envían country_id o city_id, incluso si $filter es 'all'.
                $this->applyLocationFilters($sellerIdsQuery, $request); // <-- Aplicar filtro de ubicación
                $sellerIds = $sellerIdsQuery->pluck('id');

                // 2. Obtener IDs de créditos y usuarios filtrados
                $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');
                $userIds = User::whereHas('seller', function ($query) use ($sellerIds) {
                    $query->whereIn('id', $sellerIds);
                })->pluck('id');

                // 3. Obtener IDs de cuotas (Installments) filtradas
                $installmentIds = Installment::whereIn('credit_id', $creditIds)->pluck('id');

                // 4. Cálculos filtrados por tiempo y ubicación
                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                $collected = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');
                $newCredits = Credit::whereIn('seller_id', $sellerIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->whereNull('renewed_from_id')->sum('credit_value');

                // $policy = Policy::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$start, $end])->sum('value');

                $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', $installmentIds)
                    ->whereBetween('created_at', [$start, $end])->sum('applied_amount');

                $totalPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');

                $profit = $totalPayments - $totalCapitalPaid;

                $sellers = Seller::whereIn('id', $sellerIds)->get();
            
            foreach ($sellers as $seller) {
                $sellerUserId = $seller->user_id;

                // A. Ingresos del vendedor
                $sellerIncome = Income::where('user_id', $sellerUserId)
                    ->whereBetween('created_at', [$start, $end])->sum('value');

                // B. Gastos del vendedor
                $sellerExpenses = Expense::where('user_id', $sellerUserId)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                
                // C. Recaudado (Cobros) del vendedor
                $sellerCreditIds = Credit::where('seller_id', $seller->id)->pluck('id');
                $sellerCollected = Payment::whereIn('credit_id', $sellerCreditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');

                // D. Agregar al array de desglose
                $sellerBreakdown[] = [
                    'seller_id' => $seller->id,
                    'name' => $seller->user->name ?? 'Vendedor sin nombre', 
                    'income' => (float) number_format($sellerIncome, 2, '.', ''),
                    'expenses' => (float) number_format($sellerExpenses, 2, '.', ''),
                    'collected' => (float) number_format($sellerCollected, 2, '.', ''),
                ];
            }

                // ------------------------------------------------------------------
                // ROL 2 (EMPRESA - Filtrado por Compañía + Filtros de Ubicación)
                // ------------------------------------------------------------------
            } elseif ($role === 2) {
                if (!$user->company) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene compañía asociada'
                    ]);
                }
                $companyId = $user->company->id;

                // 1. Obtener IDs de vendedores filtrados por Compañía Y Ubicación
                $sellerIdsQuery = Seller::where('company_id', $companyId);
                $this->applyLocationFilters($sellerIdsQuery, $request); // <-- Aplicar filtro de ubicación
                $sellerIds = $sellerIdsQuery->pluck('id');

                // 2. Obtener IDs de créditos y usuarios filtrados
                $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id');
                $userIds = User::whereHas('seller', function ($query) use ($sellerIds) {
                    $query->whereIn('id', $sellerIds);
                })->pluck('id');
                $installmentIds = Installment::whereIn('credit_id', $creditIds)->pluck('id');

                // 3. Cálculos filtrados por tiempo, compañía y ubicación
                $income = Income::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                $expenses = Expense::whereIn('user_id', $userIds)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                $collected = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');
                $newCredits = Credit::whereIn('seller_id', $sellerIds)
                    ->whereNull('renewed_from_id')
                    ->whereBetween('created_at', [$start, $end])->sum('credit_value');

                // $policy = Policy::whereIn('seller_id', $sellerIds)->whereBetween('created_at', [$start, $end])->sum('value');

                $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', $installmentIds)
                    ->whereBetween('created_at', [$start, $end])->sum('applied_amount');

                $totalPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');

                $profit = $totalPayments - $totalCapitalPaid;

                $sellers = Seller::whereIn('id', $sellerIds)->get();
            
            foreach ($sellers as $seller) {
                $sellerUserId = $seller->user_id;

                // A. Ingresos del vendedor
                $sellerIncome = Income::where('user_id', $sellerUserId)
                    ->whereBetween('created_at', [$start, $end])->sum('value');

                // B. Gastos del vendedor
                $sellerExpenses = Expense::where('user_id', $sellerUserId)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                
                // C. Recaudado (Cobros) del vendedor
                $sellerCreditIds = Credit::where('seller_id', $seller->id)->pluck('id');
                $sellerCollected = Payment::whereIn('credit_id', $sellerCreditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');

                // D. Agregar al array de desglose
                $sellerBreakdown[] = [
                    'seller_id' => $seller->id,
                    'name' => $seller->user->name ?? 'Vendedor sin nombre',
                    'income' => (float) number_format($sellerIncome, 2, '.', ''),
                    'expenses' => (float) number_format($sellerExpenses, 2, '.', ''),
                    'collected' => (float) number_format($sellerCollected, 2, '.', ''),
                ];
            }

                // ------------------------------------------------------------------
                // ROL 5 (VENDEDOR - Filtrado por Vendedor)
                // ------------------------------------------------------------------
            } elseif ($role === 5) {
                $seller = $user->seller;
                if (!$seller) {
                    return $this->successResponse([
                        'success' => true,
                        'data' => [],
                        'message' => 'Usuario no tiene vendedor asociado'
                    ]);
                }

                $income = Income::where('user_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])->sum('value');
                $expenses = Expense::where('user_id', $user->id)
                    ->whereBetween('created_at', [$start, $end])->sum('value');

                $creditIds = Credit::where('seller_id', $seller->id)->pluck('id');
                $installmentIds = Installment::whereIn('credit_id', $creditIds)->pluck('id');

                $collected = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');
                $newCredits = Credit::where('seller_id', $seller->id)
                    ->whereNull('renewed_from_id')
                    ->whereBetween('created_at', [$start, $end])->sum('credit_value');

                $totalCapitalPaid = PaymentInstallment::whereIn('installment_id', $installmentIds)
                    ->whereBetween('created_at', [$start, $end])->sum('applied_amount');
                $totalPayments = Payment::whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');
                $profit = $totalPayments - $totalCapitalPaid;
                $sellerBreakdown[] = [
                    'seller_id' => $seller->id,
                    'name' => $user->name ?? 'Mi Movimiento',
                    'income' => (float) number_format($income, 2, '.', ''),
                    'expenses' => (float) number_format($expenses, 2, '.', ''),
                    'collected' => (float) number_format($collected, 2, '.', ''),
                ];
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'income' => (float) number_format($income, 2, '.', ''),
                    'expenses' => (float) number_format($expenses, 2, '.', ''),
                    'collected' => (float) number_format($collected, 2, '.', ''),
                    'newCredits' => (float) number_format($newCredits, 2, '.', ''),
                    'policy' => (float) number_format($policy, 2, '.', ''),
                    'profit' => (float) number_format($profit, 2, '.', ''),
                    'seller_breakdown' => $sellerBreakdown,
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
