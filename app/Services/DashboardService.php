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
use App\Models\UserRoute;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class DashboardService
{
    use ApiResponse;

    private const TIMEZONE = 'America/Lima';

    /**
     * Apply location filters (country_id / city_id) to a query builder that has city relation or city_id column.
     */
    protected function applyLocationFilters(Builder $query, Request $request): Builder
    {
        $countryId = $request->input('country_id');
        $cityId = $request->input('city_id');

        if ($cityId) {
            // If the model has a city_id column
            if (SchemaHasColumn($query->getModel()->getTable(), 'city_id')) {
                $query->where('city_id', $cityId);
            } else {
                $query->whereHas('city', function ($q) use ($cityId) {
                    $q->where('id', $cityId);
                });
            }
        } elseif ($countryId) {
            $query->whereHas('city', function ($q) use ($countryId) {
                $q->where('country_id', $countryId);
            });
        }

        return $query;
    }

    /**
     * Return seller IDs relevant for current user + optional location filters.
     */
    private function getSellerIdsForUser(User $user, Request $request = null, $companyId = null): Collection
    {
        $role = $user->role_id;

        if ($role === 5 && $user->seller) {
            return collect([$user->seller->id]);
        }
        // Consultor: solo los sellers asociados en UserRoute
        if ($role === 11) {
            return UserRoute::where('user_id', $user->id)->pluck('seller_id')->unique()->values();
        }

        $sellersQuery = Seller::query();

        if ($role === 2) {
            if (!$user->company) {
                return collect();
            }
            $sellersQuery->where('company_id', $user->company->id);
        }
        // Filtrar por company_id si el usuario es admin y el parámetro está presente
        if ($role === 1 && $companyId) {
            $sellersQuery->where('company_id', $companyId);
        }
        if ($request) {
            $this->applyLocationFilters($sellersQuery, $request);
        }

        return $sellersQuery->pluck('id')->unique()->values();
    }

    /**
     * Given seller ids returns credit ids.
     */
    private function getCreditIdsForSellers(Collection $sellerIds): Collection
    {
        if ($sellerIds->isEmpty()) {
            return collect();
        }
        return Credit::whereIn('seller_id', $sellerIds)->pluck('id')->unique()->values();
    }

    /**
     * Given seller ids returns user ids (users that belong to those sellers).
     */
    private function getUserIdsForSellers(Collection $sellerIds): Collection
    {
        if ($sellerIds->isEmpty()) {
            return collect();
        }

        return User::whereHas('seller', function ($q) use ($sellerIds) {
            $q->whereIn('id', $sellerIds);
        })->pluck('id')->unique()->values();
    }

    /**
     * Helper to precompute payments sum grouped by credit_id, optional date range.
     * Returns a Collection keyed by credit_id => total
     */
    private function getPaymentsSumByCredit(array $creditIds, ?string $startUtc = null, ?string $endUtc = null): Collection
    {
        if (empty($creditIds)) {
            return collect();
        }

        $q = Payment::whereIn('credit_id', $creditIds);

        if ($startUtc && $endUtc) {
            $q->whereBetween('created_at', [$startUtc, $endUtc]);
        }

        return $q->select('credit_id', DB::raw('SUM(amount) as total'))
            ->groupBy('credit_id')
            ->get()
            ->pluck('total', 'credit_id')
            ->map(function ($v) {
                return (float) $v;
            });
    }

    /**
     * Precompute total payments (all time) by credit_id
     */
    private function getTotalPaymentsByCredit(array $creditIds): Collection
    {
        return $this->getPaymentsSumByCredit($creditIds);
    }

    /**
     * Precompute renewals grouped by seller_id for a given date range (or single date).
     */
    private function getRenewalsGroupedBySeller(array $sellerIds, ?string $startUtc = null, ?string $endUtc = null): Collection
    {
        if (empty($sellerIds)) {
            return collect();
        }

        $q = Credit::whereIn('seller_id', $sellerIds)
            ->whereNotNull('renewed_from_id');

        if ($startUtc && $endUtc) {
            $q->whereBetween('created_at', [$startUtc, $endUtc]);
        }

        return $q->get()->groupBy('seller_id');
    }

    /**
     * Get sum of last approved liquidation real_to_deliver before $beforeDate per seller list.
     * It returns the sum of the last (max date) liquidation per seller.
     */
    private function getLastLiquidationsSum(array $sellerIds, string $beforeDate): float
    {
        if (empty($sellerIds)) {
            return 0.0;
        }

        $sub = Liquidation::selectRaw('MAX(date) as max_date, seller_id')
            ->whereIn('seller_id', $sellerIds)
            ->where('date', '<', $beforeDate)
            ->groupBy('seller_id');

        $initialCash = Liquidation::query()
            ->joinSub($sub, 'last_liquidations', function ($join) {
                $join->on('liquidations.seller_id', '=', 'last_liquidations.seller_id')
                    ->on('liquidations.date', '=', 'last_liquidations.max_date');
            })
            ->whereIn('liquidations.seller_id', $sellerIds)
            ->sum('real_to_deliver');

        return (float) $initialCash;
    }

    /**
     * Load counters for dashboard: members, routes, credits, clients
     */
    public function loadCounters(Request $request, $companyId = null)
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
                if ($companyId) {
                    $routesQuery->where('company_id', $companyId);
                }
                $data['routes'] = $this->applyLocationFilters($routesQuery, $request)->count();

                // CRÉDITOS & CLIENTES: Obtenemos los IDs de los vendedores filtrados por ubicación
                $sellerIdsQuery = Seller::query();
                if ($companyId) {
                    $sellerIdsQuery->where('company_id', $companyId);
                }
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
            // Consultor: solo los sellers asociados en UserRoute
            } elseif ($role === 11) {
                $sellerIds = UserRoute::where('user_id', $user->id)->pluck('seller_id')->toArray();
                $data['routes'] = count($sellerIds);
                $data['members'] = User::whereHas('seller', function ($query) use ($sellerIds) {
                    $query->whereIn('id', $sellerIds);
                })->count();
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)->count();
            }

            // Filtro por vendedor si se recibe seller_id
            $sellerId = $request->input('seller_id');
            if ($sellerId) {
                // Filtrar todos los conteos solo por ese vendedor
                $data['routes'] = 1;
                $data['members'] = User::whereHas('seller', function ($query) use ($sellerId) {
                    $query->where('id', $sellerId);
                })->count();
                $data['credits'] = Credit::where('seller_id', $sellerId)->count();
                $data['clients'] = Client::where('seller_id', $sellerId)->count();
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
    /**
     * Create a lighter, optimized version of loadPendingPortfolios.
     * - Pre-aggregates payments
     * - Avoids N+1 queries
     * - Uses DB aggregates for heavy sums
     */
    public function loadPendingPortfolios(Request $request, $companyId = null)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $timezone  = 'America/Lima';
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
            if ($role === 1 && $companyId) {
                $sellersQuery->where('company_id', $companyId);
            }
            if (!in_array($role, [1, 2, 5])) {
                return response()->json(['success' => true, 'data' => []]);
            }

            if (in_array($role, [1, 2])) {
                $this->applyLocationFilters($sellersQuery, $request);
            }

            // Filtro por vendedor si se recibe seller_id
            $sellerId = $request->input('seller_id');
            if ($sellerId) {
                $sellersQuery->where('id', $sellerId);
            }

            $sellers = $sellersQuery->take(10)->get();
            $result = [];

            $allCreditIds = $sellers->pluck('credits')->flatten()->pluck('id')->all();

            $totalPaidByCredit = Payment::whereIn('credit_id', $allCreditIds)
                ->select('credit_id', DB::raw('SUM(amount) as total'))
                ->groupBy('credit_id')
                ->get()
                ->pluck('total', 'credit_id')
                ->map(function ($v) {
                    return (float) $v;
                })
                ->all();

            $paidTodayByCredit = Payment::whereIn('credit_id', $allCreditIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->select('credit_id', DB::raw('SUM(amount) as total'))
                ->groupBy('credit_id')
                ->get()
                ->pluck('total', 'credit_id')
                ->map(function ($v) {
                    return (float) $v;
                })
                ->all();

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

                    $totalPagado = $totalPaidByCredit[$credit->id] ?? 0;

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
                    $totalPaid = $totalPaidByCredit[$credit->id] ?? 0;

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

                    $paymentsTodayTotal = $paidTodayByCredit[$credit->id] ?? 0;

                    $collectedTodayCapital = $paymentsTodayTotal * $percentageCapital;
                    $collectedTodayUtility = $paymentsTodayTotal * $percentageUtility;

                    $sellerData['collected_today']['C'] += $collectedTodayCapital;
                    $sellerData['collected_today']['U'] += $collectedTodayUtility;
                    $sellerData['collected_today']['T'] += $paymentsTodayTotal;

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
                    ->whereNull('deleted_at')
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

    /**
     * Helper to get seller location string
     */
    private function getSellerLocation(Seller $seller): string
    {
        if (!$seller->city) {
            return 'Ubicación no definida';
        }

        $city = $seller->city->name;
        $country = $seller->city->country->name ?? 'País no definido';

        return "$city, $country";
    }

    /**
     * Load the financial summary (optimized and consolidated version).
     */
    public function loadFinancialSummary(Request $request, $companyId = null)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;

            $timezone = self::TIMEZONE;
            $today = Carbon::now($timezone)->toDateString();
            $startUTC = Carbon::now($timezone)->startOfDay()->timezone('UTC')->toDateTimeString();
            $endUTC = Carbon::now($timezone)->endOfDay()->timezone('UTC')->toDateTimeString();

            $totalBalance = $capitalPending = $profitPending = $currentCash = 0;
            $incomeTotal = $expenseTotal = $newCredits = $total_renewal_disbursed = 0;
            $cashDayBalance = 0;
            $dailyPolicy = 0;
            $irrecoverableCredits = 0;
            $initialCash = 0;

            // get seller ids relevant
            $sellerIds = $this->getSellerIdsForUser($user, $request, $companyId)->all();

            // Filtro por vendedor si se recibe seller_id
            $sellerId = $request->input('seller_id');
            if ($sellerId) {
                $sellerIds = collect([$sellerId]);
            }

            if (empty($sellerIds)) {
                return $this->successResponse(['success' => true, 'data' => [
                    'totalBalance' => 0,
                    'capital' => 0,
                    'profit' => 0,
                    'currentCash' => 0,
                    'cashDayBalance' => 0,
                    'income' => 0,
                    'expenses' => 0
                ]]);
            }

            $creditIds = $this->getCreditIdsForSellers(collect($sellerIds))->all();
            $userIds = $this->getUserIdsForSellers(collect($sellerIds))->all();

            // CÁLCULOS PRINCIPALES DE CARTERA
            if (!empty($creditIds)) {
                $totalBalance = (float) Credit::whereIn('id', $creditIds)
                    ->sum(DB::raw('credit_value + (credit_value * total_interest / 100)'));

                $totalCapitalPaid = (float) PaymentInstallment::whereIn('installment_id', function ($q) use ($creditIds) {
                    $q->select('id')->from('installments')->whereIn('credit_id', $creditIds);
                })->sum('applied_amount');

                $totalPayments = (float) Payment::whereIn('credit_id', $creditIds)->sum('amount');
                $totalProfitPaid = $totalPayments - $totalCapitalPaid;
                $capitalPending = max(0, $totalBalance - $totalCapitalPaid);

                $totalExpectedProfit = (float) Credit::whereIn('id', $creditIds)
                    ->sum(DB::raw('credit_value * total_interest / 100'));
                $profitPending = max(0, $totalExpectedProfit - $totalProfitPaid);
            }

            // Ingresos / Gastos
            $incomeTotal = (float) Income::whereIn('user_id', $userIds)->sum('value');
            $expenseTotal = (float) Expense::whereIn('user_id', $userIds)->sum('value');

            // initial cash: sum of last liquidation per seller (prior to today)
            $initialCash = $this->getLastLiquidationsSum(is_array($sellerIds) ? $sellerIds : (method_exists($sellerIds, 'all') ? $sellerIds->all() : (array)$sellerIds), $today);

            // Flujos del día
            $cashPayments = (float) Payment::whereIn('credit_id', $creditIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])->sum('amount');
            $expenses = (float) Expense::whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])->where('status', 'Aprobado')->sum('value');
            $income = (float) Income::whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])->sum('value');

            $newCredits = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->whereNull('renewed_from_id')
                ->whereNull('deleted_at')
                ->sum('credit_value');

            // Renovaciones: compute net disbursement
            $renewalCredits = Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->whereNotNull('renewed_from_id')
                ->get();

            $total_renewal_disbursed = (float) $renewalCredits->sum(function ($renewCredit) {
                $oldCredit = Credit::find($renewCredit->renewed_from_id);
                $pendingAmount = 0;
                if ($oldCredit) {
                    $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                    $oldCreditPaid = Payment::where('credit_id', $oldCredit->id)->sum('amount');
                    $pendingAmount = max(0, $oldCreditTotal - $oldCreditPaid);
                }
                return $renewCredit->credit_value - $pendingAmount;
            });

            // daily policy
            $dailyPolicy = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->whereNull('deleted_at')
                ->get()
                ->sum(fn($credit) => ($credit->credit_value * ($credit->micro_insurance_percentage ?? 0) / 100));

            // irrecoverable
            $irrecoverableCredits = (float) DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->where('credits.status', 'Cartera Irrecuperable')
                ->whereDate('credits.updated_at', $today)
                ->where('installments.status', 'Pendiente')
                ->sum('installments.quota_amount');

            $currentCash = $initialCash + ($income + $cashPayments + $dailyPolicy) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);
            $cashDayBalance = ($income + $cashPayments + $dailyPolicy) - ($expenses + $newCredits + $total_renewal_disbursed + $irrecoverableCredits);

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
            Log::error("Error loading financial summary: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener el resumen financiero.', 500);
        }
    }

    /**
     * Weekly financial summary (optimized).
     */
    public function weeklyFinancialSummary(Request $request, $companyId = null)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;
            $timezone = self::TIMEZONE;

            $startOfWeekUtc = Carbon::now($timezone)->startOfWeek()->timezone('UTC')->toDateTimeString();
            $endOfWeekUtc = Carbon::now($timezone)->endOfWeek()->timezone('UTC')->toDateTimeString();
            $startOfWeekDate = Carbon::now($timezone)->startOfWeek()->toDateString();

            $sellerIds = $this->getSellerIdsForUser($user, $request, $companyId)->all();

            // Filtro por vendedor si se recibe seller_id
            $sellerId = $request->input('seller_id');
            if ($sellerId) {
                $sellerIds = [$sellerId];
            }

            if (empty($sellerIds)) {
                return $this->successResponse(['success' => true, 'data' => []]);
            }

            $creditIds = $this->getCreditIdsForSellers(collect($sellerIds))->all();
            $userIds = $this->getUserIdsForSellers(collect($sellerIds))->all();

            $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                ->orderBy('date', 'asc')
                ->whereDate('date', $startOfWeekDate)
                ->first();
            $initialCash = $lastLiquidation ? (float) $lastLiquidation->real_to_deliver : 0.0;

            $income = (float) Income::whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$startOfWeekUtc, $endOfWeekUtc])->sum('value');

            $cashPayments = (float) Payment::whereIn('credit_id', $creditIds)
                ->whereBetween('created_at', [$startOfWeekUtc, $endOfWeekUtc])->sum('amount');

            $newCredits = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$startOfWeekUtc, $endOfWeekUtc])
                ->whereNull('renewed_from_id')->sum('credit_value');

            $expenses = (float) Expense::whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$startOfWeekUtc, $endOfWeekUtc])->sum('value');

            $irrecoverableCredits = (float) DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->where('credits.status', 'Cartera Irrecuperable')
                ->whereBetween('credits.updated_at', [$startOfWeekUtc, $endOfWeekUtc])
                ->where('installments.status', 'Pendiente')
                ->sum('installments.quota_amount');

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
            Log::error("Error loading weekly financial summary: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener el balance general semanal.', 500);
        }
    }

    /**
     * Weekly movements (day/week/month/all) optimized.
     */
    public function weeklyMovements(Request $request, $companyId = null)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;
            $timezone = self::TIMEZONE;

            $filter = $request->input('filter', 'all');

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

            $sellerIds = $this->getSellerIdsForUser($user, $request, $companyId)->all();

            // Filtro por vendedor si se recibe seller_id
            $sellerId = $request->input('seller_id');
            if ($sellerId) {
                $sellerIds = [$sellerId];
            }

            if (empty($sellerIds)) {
                return $this->successResponse(['success' => true, 'data' => []]);
            }

            $creditIds = $this->getCreditIdsForSellers(collect($sellerIds))->all();
            $userIds = $this->getUserIdsForSellers(collect($sellerIds))->all();

            $income = (float) Income::whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$start, $end])->sum('value');

            $expenses = (float) Expense::whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$start, $end])->sum('value');

            $collected = (float) Payment::whereIn('credit_id', $creditIds)
                ->whereBetween('created_at', [$start, $end])->sum('amount');

            $newCredits = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$start, $end])->whereNull('renewed_from_id')->sum('credit_value');

            $installmentIds = Installment::whereIn('credit_id', $creditIds)->pluck('id')->all();
            $totalCapitalPaid = (float) PaymentInstallment::whereIn('installment_id', $installmentIds)
                ->whereBetween('created_at', [$start, $end])->sum('applied_amount');

            $totalPayments = (float) Payment::whereIn('credit_id', $creditIds)
                ->whereBetween('created_at', [$start, $end])->sum('amount');

            $profit = max(0, $totalPayments - $totalCapitalPaid);

            $sellerBreakdown = [];
            $sellers = Seller::whereIn('id', $sellerIds)->get();
            foreach ($sellers as $seller) {
                $sellerIncome = (float) Income::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [$start, $end])->sum('value');

                $sellerExpenses = (float) Expense::where('user_id', $seller->user_id)
                    ->whereBetween('created_at', [$start, $end])->sum('value');

                $sellerCreditIds = Credit::where('seller_id', $seller->id)->pluck('id')->all();
                $sellerCollected = (float) Payment::whereIn('credit_id', $sellerCreditIds)
                    ->whereBetween('created_at', [$start, $end])->sum('amount');

                $sellerBreakdown[] = [
                    'seller_id' => $seller->id,
                    'name' => $seller->user?->name ?? 'Vendedor sin nombre',
                    'income' => (float) number_format($sellerIncome, 2, '.', ''),
                    'expenses' => (float) number_format($sellerExpenses, 2, '.', ''),
                    'collected' => (float) number_format($sellerCollected, 2, '.', ''),
                ];
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'income' => (float) number_format($income, 2, '.', ''),
                    'expenses' => (float) number_format($expenses, 2, '.', ''),
                    'collected' => (float) number_format($collected, 2, '.', ''),
                    'newCredits' => (float) number_format($newCredits, 2, '.', ''),
                    'policy' => 0,
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
            Log::error("Error loading movements: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener movimientos.', 500);
        }
    }

    public function weeklyMovementsHistory(Request $request, $sellerId = null, $companyId = null)
    {
        try {
            $filter = $request->input('filter', 'all');
            $type = $request->input('type', 'income');
            $timezone = self::TIMEZONE;
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
            $data = [];
            $user = Auth::user();
            $sellerIds = $this->getSellerIdsForUser($user, $request, $companyId)->all();
            if ($type === 'income') {
                $query = Income::with('user')->whereBetween('created_at', [$start, $end]);
                if ($sellerId) {
                    $seller = Seller::find($sellerId);
                    if ($seller && $seller->user_id) {
                        $query->where('user_id', $seller->user_id);
                    } else {
                        $query->whereRaw('0 = 1');
                    }
                } elseif (!empty($sellerIds)) {
                    $userIds = Seller::whereIn('id', $sellerIds)->pluck('user_id')->all();
                    $query->whereIn('user_id', $userIds);
                }
                $incomes = $query->orderBy('created_at', 'asc')->get();
                $grouped = [];
                foreach ($incomes as $income) {
                    $date = $income->created_at->format('Y-m-d');
                    if (!isset($grouped[$date])) $grouped[$date] = [];
                    $grouped[$date][] = [
                        'value' => $income->value,
                        'user' => $income->user ? $income->user->name : 'Sin usuario',
                        'description' => $income->description ?? '',
                    ];
                }
                foreach ($grouped as $date => $items) {
                    foreach ($items as $item) {
                        $data[] = [
                            'date' => $date,
                            'value' => $item['value'],
                            'user' => $item['user'],
                            'description' => $item['description'],
                        ];
                    }
                }
            } elseif ($type === 'expenses') {
                $query = Expense::with('user')->whereBetween('created_at', [$start, $end]);
                if ($sellerId) {
                    $seller = Seller::find($sellerId);
                    if ($seller && $seller->user_id) {
                        $query->where('user_id', $seller->user_id);
                    } else {
                        $query->whereRaw('0 = 1');
                    }
                } elseif (!empty($sellerIds)) {
                    $userIds = Seller::whereIn('id', $sellerIds)->pluck('user_id')->all();
                    $query->whereIn('user_id', $userIds);
                }
                $expenses = $query->orderBy('created_at', 'asc')->get();
                $grouped = [];
                foreach ($expenses as $expense) {
                    $date = $expense->created_at->format('Y-m-d');
                    if (!isset($grouped[$date])) $grouped[$date] = [];
                    $grouped[$date][] = [
                        'value' => $expense->value,
                        'user' => $expense->user ? $expense->user->name : 'Sin usuario',
                        'description' => $expense->description ?? '',
                    ];
                }
                foreach ($grouped as $date => $items) {
                    foreach ($items as $item) {
                        $data[] = [
                            'date' => $date,
                            'value' => $item['value'],
                            'user' => $item['user'],
                            'description' => $item['description'],
                        ];
                    }
                }
            } elseif ($type === 'collected') {
                if ($sellerId) {
                    $creditIds = Credit::where('seller_id', $sellerId)->pluck('id')->all();
                } elseif (!empty($sellerIds)) {
                    $creditIds = Credit::whereIn('seller_id', $sellerIds)->pluck('id')->all();
                } else {
                    $creditIds = Credit::pluck('id')->all();
                }
                $payments = Payment::with(['credit.seller', 'credit.seller.user'])
                    ->whereIn('credit_id', $creditIds)
                    ->whereBetween('created_at', [$start, $end])
                    ->orderBy('created_at', 'asc')
                    ->get();
                $grouped = [];
                foreach ($payments as $payment) {
                    $date = $payment->created_at->format('Y-m-d');
                    if (!isset($grouped[$date])) $grouped[$date] = [];
                    $sellerName = $payment->credit && $payment->credit->seller && $payment->credit->seller->user
                        ? $payment->credit->seller->user->name
                        : 'Sin vendedor';
                    $clientName = $payment->credit && $payment->credit->client
                        ? $payment->credit->client->name
                        : 'Sin cliente';
                    $grouped[$date][] = [
                        'value' => $payment->amount,
                        'seller' => $sellerName,
                        'client' => $clientName,
                        'payment_id' => $payment->id,
                    ];
                }
                foreach ($grouped as $date => $items) {
                    foreach ($items as $item) {
                        $data[] = [
                            'date' => $date,
                            'value' => $item['value'],
                            'seller' => $item['seller'],
                            'client' => $item['client'],
                            'payment_id' => $item['payment_id'],
                        ];
                    }
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => $data,
                'period' => [
                    'start' => $start,
                    'end' => $end,
                    'filter' => $filter,
                    'type' => $type,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error loading movements history: {$e->getMessage()} | " . $e->getTraceAsString());
            return $this->errorResponse('Error al obtener histórico de movimientos.', 500);
        }
    }
}

/**
 * Small helper to check if table has a column.
 * It's declared here to avoid adding a new dependency. If you already
 * have a schema helper in the project replace its usage.
 */
if (!function_exists('SchemaHasColumn')) {
    function SchemaHasColumn(string $table, string $column): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            // if schema info not available simply return false to not break logic
            return false;
        }
    }
}
