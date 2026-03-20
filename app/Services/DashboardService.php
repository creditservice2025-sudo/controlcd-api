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
        if ($role !== 5 && $role !== 1 && $role !== 2) {
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
                'clients_no_credits' => 0,
            ];

            // ------------------------------------------------------------------
            // ROL 1 (ADMIN - Global con filtros de ubicación)
            // ------------------------------------------------------------------
            if ($role === 1) {
                // MIEMBROS: Usuarios filtrados por ubicación a través de su Vendedor (Seller)
                $data['members'] = User::whereHas('seller', function ($query) use ($request, $companyId) {
                    // Aplicamos filtros de ubicación al Vendedor asociado al Usuario
                    $this->applyLocationFilters($query, $request);
                    if ($companyId) {
                        $query->where('company_id', $companyId);
                    }
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
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)
                    ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable'])
                    ->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)
                    ->whereHas('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();
                $data['clients_no_credits'] = Client::whereIn('seller_id', $sellerIds)
                    ->whereDoesntHave('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();


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
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)
                    ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable'])
                    ->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)
                    ->whereHas('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();
                $data['clients_no_credits'] = Client::whereIn('seller_id', $sellerIds)
                    ->whereDoesntHave('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();


                // ------------------------------------------------------------------
                // ROL 5 (VENDEDOR - Filtrado solo por Vendedor, ubicación no aplica)
                // ------------------------------------------------------------------
            } elseif ($role === 5) {
                // Se mantiene igual, ya está filtrado por el vendedor logueado
                $seller = $user->seller;
                if ($seller) {
                    $data['credits'] = $seller->credits()
                        ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable'])
                        ->count();
                    $data['clients'] = Client::where('seller_id', $seller->id)
                        ->whereHas('credits', function ($query) {
                            $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                        })->count();
                    $data['clients_no_credits'] = Client::where('seller_id', $seller->id)
                        ->whereDoesntHave('credits', function ($query) {
                            $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                        })->count();
                }
                // Consultor: solo los sellers asociados en UserRoute
            } else {
                $sellerIds = UserRoute::where('user_id', $user->id)->pluck('seller_id')->toArray();
                $data['routes'] = count($sellerIds);
                $data['members'] = User::whereHas('seller', function ($query) use ($sellerIds) {
                    $query->whereIn('id', $sellerIds);
                })->count();
                $data['credits'] = Credit::whereIn('seller_id', $sellerIds)
                    ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable'])
                    ->count();
                $data['clients'] = Client::whereIn('seller_id', $sellerIds)
                    ->whereHas('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();
                $data['clients_no_credits'] = Client::whereIn('seller_id', $sellerIds)
                    ->whereDoesntHave('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();
            }

            // Filtro por vendedor si se recibe seller_id
            $sellerId = $request->input('seller_id');
            if ($sellerId) {
                // Filtrar todos los conteos solo por ese vendedor
                $data['routes'] = 1;
                $data['members'] = User::whereHas('seller', function ($query) use ($sellerId) {
                    $query->where('id', $sellerId);
                })->count();
                $data['credits'] = Credit::where('seller_id', $sellerId)
                    ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable'])
                    ->count();
                $data['clients'] = Client::where('seller_id', $sellerId)
                    ->whereHas('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();
                $data['clients_no_credits'] = Client::where('seller_id', $sellerId)
                    ->whereDoesntHave('credits', function ($query) {
                        $query->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable']);
                    })->count();
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

            $timezone = 'America/Lima';
            $startUTC = Carbon::now($timezone)->startOfDay()->timezone('UTC');
            $endUTC = Carbon::now($timezone)->endOfDay()->timezone('UTC');
            $todayDate = Carbon::now($timezone)->toDateString();

            $sellersQuery = Seller::query()
                ->join('users', 'sellers.user_id', '=', 'users.id')
                ->select('sellers.id', 'sellers.uuid', 'sellers.user_id', 'sellers.city_id', 'sellers.status', 'users.name')
                ->with([
                    'city:id,name,country_id',
                    'city.country:id,name',
                    'user:id,name',
                ])
                ->whereHas('credits', function ($query) {
                    $query->whereNull('deleted_at');
                })
                ->orderBy('users.name', 'asc');

            if ($role === 2 && !$user->company) {
                return response()->json(['success' => true, 'data' => []]);
            }
            if ($role === 2)
                $sellersQuery->where('sellers.company_id', $user->company->id);
            if ($role === 5)
                $sellersQuery->where('sellers.user_id', $user->id);
            if ($role === 1 && $companyId) {
                $sellersQuery->where('sellers.company_id', $companyId);
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
                $sellersQuery->where('sellers.id', $sellerId);
            }

            // Aumentamos el límite para ver todas las rutas (ej. 200) y optimizamos la carga masiva
            $sellersCount = (clone $sellersQuery)->count();
            $sellers = $sellersQuery->limit(200)->get();
            $result = [];

            $sellerIds = $sellers->pluck('id')->toArray();
            $userIds = $sellers->pluck('user_id')->filter()->unique()->toArray();

            if (empty($sellerIds)) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // 1. Agregaciones de Cartera (Inicial y Cobrado All Time)
            // Calculamos Capital e Interés por separado usando el ratio de cada crédito en SQL
            $portfolioAggs = DB::table('credits')
                ->leftJoin('payments', function($join) {
                    $join->on('credits.id', '=', 'payments.credit_id')
                         ->whereNull('payments.deleted_at');
                })
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereNull('credits.deleted_at')
                ->select(
                    'credits.seller_id',
                    // Totales All Time (incluyendo irrecuperables)
                    DB::raw("SUM(DISTINCT credits.id) as count_credits"), // Solo informativo
                    DB::raw("SUM(credits.credit_value) as total_cap_init_all"),
                    DB::raw("SUM(credits.credit_value * credits.total_interest / 100) as total_util_init_all"),
                    
                    // Totales No Irrecuperables (para 'to_collect' e 'initial_portfolio' visual)
                    DB::raw("SUM(IF(credits.status != 'Cartera Irrecuperable', credits.credit_value, 0)) as init_cap_non_irr"),
                    DB::raw("SUM(IF(credits.status != 'Cartera Irrecuperable', credits.credit_value * credits.total_interest / 100, 0)) as init_util_non_irr"),

                    // Cobrado (Necesitamos la suma de pagos escalados por el ratio de cada crédito)
                    // Nota: Usar subquery o Map en PHP para los pagos es a veces más seguro si el JOIN multiplica filas.
                    // Pero para optimizar máximo, calcularemos el cobrado total por crédito primero.
                )
                ->groupBy('credits.seller_id')
                ->get()
                ->keyBy('seller_id');

            // 2. Pagos por Crédito Agrupados por Vendedor (Para precisión de Ratios)
            $paymentAggs = DB::table('payments')
                ->join('credits', 'payments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereNull('payments.deleted_at')
                ->whereNull('credits.deleted_at')
                ->select(
                    'credits.seller_id',
                    // All Time
                    DB::raw("SUM(payments.amount) as total_paid"),
                    DB::raw("SUM(payments.amount * (credits.credit_value / (credits.credit_value + (credits.credit_value * credits.total_interest / 100)))) as cap_paid"),
                    // All Time - Non Irrecuperable (para to_collect)
                    DB::raw("SUM(IF(credits.status != 'Cartera Irrecuperable', payments.amount, 0)) as total_paid_non_irr"),
                    DB::raw("SUM(IF(credits.status != 'Cartera Irrecuperable', payments.amount * (credits.credit_value / (credits.credit_value + (credits.credit_value * credits.total_interest / 100))), 0)) as cap_paid_non_irr"),
                    // Hoy
                    DB::raw("SUM(IF(payments.created_at BETWEEN '$startUTC' AND '$endUTC', payments.amount, 0)) as paid_today"),
                    DB::raw("SUM(IF(payments.created_at BETWEEN '$startUTC' AND '$endUTC', payments.amount * (credits.credit_value / (credits.credit_value + (credits.credit_value * credits.total_interest / 100))), 0)) as cap_paid_today"),
                    DB::raw("SUM(IF(payments.created_at BETWEEN '$startUTC' AND '$endUTC', payments.amount * ((credits.credit_value * credits.total_interest / 100) / (credits.credit_value + (credits.credit_value * credits.total_interest / 100))), 0)) as util_paid_today")
                )
                ->groupBy('credits.seller_id')
                ->get()
                ->keyBy('seller_id');

            // 3. Créditos Nuevos Hoy (incluye importados via COALESCE)
            $newCreditsTodayAgg = DB::table('credits')
                ->whereIn('seller_id', $sellerIds)
                ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
                ->whereNull('renewed_from_id')
                ->whereNull('deleted_at')
                ->select(
                    'seller_id',
                    DB::raw("SUM(credit_value) as cap"),
                    DB::raw("SUM(credit_value * total_interest / 100) as util")
                )
                ->groupBy('seller_id')
                ->get()
                ->keyBy('seller_id');

            // 4. Última liquidación (para Saldo Inicial)
            $latestLiquidationDateSub = Liquidation::select('seller_id', DB::raw('MAX(date) as max_date'))
                ->whereIn('seller_id', $sellerIds)
                ->where('date', '<', $todayDate)
                ->groupBy('seller_id');

            $lastLiquidations = Liquidation::joinSub($latestLiquidationDateSub, 'latest', function ($join) {
                    $join->on('liquidations.seller_id', '=', 'latest.seller_id')
                         ->on('liquidations.date', '=', 'latest.max_date');
                })
                ->get()
                ->keyBy('seller_id');

            // 5. Gastos e Ingresos
            $expensesTodayAgg = DB::table('expenses')
                ->whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->where(function ($q) {
                    $q->where('status', 'Aprobado')
                        ->orWhere('description', 'like', '%AJUSTE%');
                })
                ->select('user_id', DB::raw("SUM(value) as total"))
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $incomeTodayAgg = DB::table('incomes')
                ->whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->select('user_id', DB::raw("SUM(value) as total"))
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            // 6. Renovaciones y Créditos Irrecuperables
            $renewalsToday = Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$startUTC, $endUTC])
                ->whereNotNull('renewed_from_id')
                ->select('id', 'seller_id', 'renewed_from_id', 'credit_value', 'total_interest')
                ->get()
                ->groupBy('seller_id');

            $renewedFromIds = $renewalsToday->flatten()->pluck('renewed_from_id')->filter()->unique()->toArray();
            
            // Para las renovaciones, necesitamos el capital/interés y pagos de los créditos antiguos
            $oldCreditsInfo = DB::table('credits')
                ->leftJoin('payments', function($join) {
                    $join->on('credits.id', '=', 'payments.credit_id')
                         ->whereNull('payments.deleted_at');
                })
                ->whereIn('credits.id', $renewedFromIds)
                ->select(
                    'credits.id',
                    DB::raw("(credits.credit_value + (credits.credit_value * credits.total_interest / 100)) as total_value"),
                    DB::raw("SUM(IFNULL(payments.amount, 0)) as total_paid")
                )
                ->groupBy('credits.id', 'credits.credit_value', 'credits.total_interest')
                ->get()
                ->keyBy('id');

            $irrecoverableToday = DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->where('credits.status', 'Cartera Irrecuperable')
                ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
                ->where('installments.status', 'Pendiente')
                ->select('credits.seller_id', DB::raw('SUM(installments.quota_amount) as total'))
                ->groupBy('credits.seller_id')
                ->get()
                ->pluck('total', 'seller_id')
                ->all();

            foreach ($sellers as $seller) {
                $location = $this->getSellerLocation($seller);
                
                $pAgg = $portfolioAggs->get($seller->id);
                $payAgg = $paymentAggs->get($seller->id);
                $newCredAgg = $newCreditsTodayAgg->get($seller->id);

                // Initial Portfolio (Non Irrecuperable)
                $initCap = $pAgg ? (float) $pAgg->init_cap_non_irr : 0;
                $initUtil = $pAgg ? (float) $pAgg->init_util_non_irr : 0;

                // Collected (All Time)
                $collTotal = $payAgg ? (float) $payAgg->total_paid : 0;
                $collCap = $payAgg ? (float) $payAgg->cap_paid : 0;
                $collUtil = $collTotal - $collCap;

                // Collected Non-Irr (for to_collect calculation)
                $collTotalNonIrr = $payAgg ? (float) $payAgg->total_paid_non_irr : 0;
                $collCapNonIrr = $payAgg ? (float) $payAgg->cap_paid_non_irr : 0;
                $collUtilNonIrr = $collTotalNonIrr - $collCapNonIrr;

                // To Collect (Non Irrecuperable)
                $toCollCap = max(0, $initCap - $collCapNonIrr);
                $toCollUtil = max(0, $initUtil - $collUtilNonIrr);
                $toCollTotal = $toCollCap + $toCollUtil;

                $sellerData = [
                    'id' => $seller->id,
                    'uuid' => $seller->uuid,
                    'route' => $seller->name,
                    'name' => $seller->user ? $seller->user->name : 'No name',
                    'location' => $location,
                    'initial_portfolio' => [
                        'T' => $initCap + $initUtil,
                        'C' => $initCap,
                        'U' => $initUtil
                    ],
                    'collected' => [
                        'T' => $collTotal,
                        'C' => $collCap,
                        'U' => $collUtil
                    ],
                    'to_collect' => [
                        'T' => $toCollTotal,
                        'C' => $toCollCap,
                        'U' => $toCollUtil
                    ],
                    'credits_today' => [
                        'T' => $newCredAgg ? ($newCredAgg->cap + $newCredAgg->util) : 0,
                        'C' => $newCredAgg ? (float) $newCredAgg->cap : 0,
                        'U' => $newCredAgg ? (float) $newCredAgg->util : 0,
                    ],
                    'collected_today' => [
                        'T' => $payAgg ? (float) $payAgg->paid_today : 0,
                        'C' => $payAgg ? (float) $payAgg->cap_paid_today : 0,
                        'U' => $payAgg ? (float) $payAgg->util_paid_today : 0,
                    ],
                    'previous_cash' => 0,
                    'current_cash' => 0,
                    'utility_collected_today' => $payAgg ? (float) $payAgg->util_paid_today : 0,
                ];

                // Renovaciones
                $sellerRenewals = $renewalsToday->get($seller->id) ?? collect();
                $total_renewal_disbursed = 0;
                $total_pending_absorbed = 0;

                foreach ($sellerRenewals as $renewCredit) {
                    $oldInfo = $oldCreditsInfo->get($renewCredit->renewed_from_id);
                    $pendingAmount = 0;
                    if ($oldInfo) {
                        $pendingAmount = max(0, (float) $oldInfo->total_value - (float) $oldInfo->total_paid);
                        $total_pending_absorbed += $pendingAmount;
                    }
                    $netDisbursement = $renewCredit->credit_value - $pendingAmount;
                    $total_renewal_disbursed += $netDisbursement;
                }

                $sellerData['total_renewal_disbursed'] = (float) number_format($total_renewal_disbursed, 2, '.', '');
                $sellerData['total_pending_absorbed'] = (float) number_format($total_pending_absorbed, 2, '.', '');

                // Caja inicial
                $lastLiquidation = $lastLiquidations->get($seller->id);
                $initialCash = $lastLiquidation ? (float) $lastLiquidation->real_to_deliver : 0;
                $sellerData['previous_cash'] = (float) number_format($initialCash, 2, '.', '');

                // Caja actual
                $expenses = (float) ($expensesTodayAgg->get($seller->user_id)->total ?? 0);
                $income = (float) ($incomeTodayAgg->get($seller->user_id)->total ?? 0);
                $newCreditsVal = $newCredAgg ? (float) $newCredAgg->cap : 0;
                $irrecoverableCreditsSum = (float) ($irrecoverableToday[$seller->id] ?? 0);
                $cashPaymentsToday = $payAgg ? (float) $payAgg->paid_today : 0;

                $currentCash = $initialCash + ($income + $cashPaymentsToday) - ($expenses + $newCreditsVal + $total_renewal_disbursed);
                $sellerData['current_cash'] = (float) number_format($currentCash, 2, '.', '');

                $result[] = $sellerData;
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'total' => $sellersCount
            ]);
        } catch (\Throwable $e) {
            \Log::error("CRITICAL ERROR in loadPendingPortfolios: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las carteras pendientes: ' . $e->getMessage()
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
                $sellerIds = collect([$sellerId])->all();
            }

            if (empty($sellerIds)) {
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
                    ]
                ]);
            }

            $creditIds = $this->getCreditIdsForSellers(collect($sellerIds))->all();
            $userIds = $this->getUserIdsForSellers(collect($sellerIds))->all();

            // CÁLCULOS PRINCIPALES DE CARTERA (Optimized using remaining_amount)
            $activeCredits = Credit::whereIn('seller_id', $sellerIds)
                ->whereNotIn('status', ['Liquidado', 'Cartera Irrecuperable', 'Anulado'])
                ->whereNull('deleted_at')
                ->selectRaw('SUM(remaining_amount) as total_remaining, SUM(credit_value * total_interest / 100) as total_expected_interest')
                ->first();
            
            $totalBalance = (float) ($activeCredits->total_remaining ?? 0);
            $totalExpectedInterest = (float) ($activeCredits->total_expected_interest ?? 0);

            // Calcular capital pagado y pagos totales solo para créditos ACTIVOS
            $totalCapitalPaid = (float) PaymentInstallment::join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereNotIn('credits.status', ['Liquidado', 'Cartera Irrecuperable', 'Anulado'])
                ->whereNull('credits.deleted_at')
                ->whereNull('installments.deleted_at')
                ->sum('payment_installments.applied_amount');

            $totalPayments = (float) Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereNotIn('credits.status', ['Liquidado', 'Cartera Irrecuperable', 'Anulado'])
                ->whereNull('credits.deleted_at')
                ->whereNull('payments.deleted_at')
                ->sum('payments.amount');

            $totalProfitPaid = max(0, $totalPayments - $totalCapitalPaid);
            
            $profitPending = max(0, $totalExpectedInterest - $totalProfitPaid);
            $capitalPending = max(0, $totalBalance - $profitPending);

            // Ingresos / Gastos (Optimized using JOINs)
            $incomeTotal = (float) Income::join('sellers', 'incomes.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->sum('incomes.value');
            
            $expenseTotal = (float) Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->sum('expenses.value');

            // initial cash: sum of last liquidation per seller (prior to today)
            $initialCash = $this->getLastLiquidationsSum($sellerIds, $today);

            // Flujos del día (Optimized using JOINs)
            $cashPayments = (float) Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->where('payments.business_date', $today)
                ->sum('payments.amount');

            $expenses = (float) Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->where('expenses.business_date', $today)
                ->where(function ($q) {
                    $q->where('expenses.status', 'Aprobado')
                        ->orWhere('expenses.description', 'like', '%AJUSTE%');
                })
                ->sum('expenses.value');

            $income = (float) Income::join('sellers', 'incomes.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->where('incomes.business_date', $today)
                ->sum('incomes.value');

            $newCredits = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
                ->whereNull('renewed_from_id')
                ->whereNull('deleted_at')
                ->sum('credit_value');

            // Renovaciones: compute net disbursement (Optimized to avoid N+1)
            $renewalCredits = Credit::whereIn('seller_id', $sellerIds)
                ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
                ->whereNotNull('renewed_from_id')
                ->get();

            $oldCreditIds = $renewalCredits->pluck('renewed_from_id')->filter()->unique()->toArray();
            $oldCreditsMap = [];
            $oldPaymentsMap = [];

            if (!empty($oldCreditIds)) {
                $oldCreditsMap = Credit::whereIn('id', $oldCreditIds)->get()->keyBy('id');
                $oldPaymentsMap = Payment::whereIn('credit_id', $oldCreditIds)
                    ->select('credit_id', DB::raw('SUM(amount) as total_paid'))
                    ->groupBy('credit_id')
                    ->pluck('total_paid', 'credit_id');
            }

            $total_renewal_disbursed = (float) $renewalCredits->sum(function ($renewCredit) use ($oldCreditsMap, $oldPaymentsMap) {
                $oldCredit = $oldCreditsMap->get($renewCredit->renewed_from_id);
                $pendingAmount = 0;
                if ($oldCredit) {
                    $oldCreditTotal = ($oldCredit->credit_value * $oldCredit->total_interest / 100) + $oldCredit->credit_value;
                    $oldCreditPaid = (float) ($oldPaymentsMap->get($oldCredit->id) ?? 0);
                    $pendingAmount = max(0, $oldCreditTotal - $oldCreditPaid);
                }
                return $renewCredit->credit_value - $pendingAmount;
            });

            // daily policy
            $dailyPolicy = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startUTC, $endUTC])
                ->whereNull('deleted_at')
                ->sum(DB::raw('micro_insurance_percentage * credit_value / 100'));

            // irrecoverable
            $irrecoverableCredits = (float) DB::table('installments')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->where('credits.status', 'Cartera Irrecuperable')
                ->whereBetween('credits.updated_at', [$startUTC, $endUTC])
                ->where('installments.status', 'Pendiente')
                ->sum('installments.quota_amount');

            $currentCash = $initialCash + ($income + $cashPayments + $dailyPolicy) - ($expenses + $newCredits + $total_renewal_disbursed);
            $cashDayBalance = ($income + $cashPayments + $dailyPolicy) - ($expenses + $newCredits + $total_renewal_disbursed);

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'totalBalance' => (float) number_format($totalBalance, 2, '.', ''),
                    'capital' => (float) number_format($capitalPending, 2, '.', ''),
                    'profit' => (float) number_format($profitPending, 2, '.', ''),
                    'currentCash' => (float) number_format($currentCash, 2, '.', ''),
                    'cashDayBalance' => (float) number_format($cashDayBalance, 2, '.', ''),
                    'income' => (float) number_format($income, 2, '.', ''),
                    'expenses' => (float) number_format($expenses, 2, '.', ''),
                    'newCredits' => (float) number_format($newCredits, 2, '.', ''),
                    'renewalCreditsDisbursed' => (float) number_format($total_renewal_disbursed, 2, '.', ''),
                    'dailyPolicy' => (float) number_format($dailyPolicy, 2, '.', ''),
                ]
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error loading financial summary: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return $this->errorResponse('Error al obtener el resumen financiero: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Weekly financial summary (optimized for massive datasets).
     */
    public function weeklyFinancialSummary(Request $request, $companyId = null)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;
            $timezone = self::TIMEZONE;

            $now = Carbon::now($timezone);
            $startOfWeekUtc = $now->copy()->startOfWeek()->timezone('UTC');
            $endOfWeekUtc = $now->copy()->endOfWeek()->timezone('UTC');
            $startOfWeekDate = $now->copy()->startOfWeek()->toDateString();
            $endOfWeekDate = $now->copy()->endOfWeek()->toDateString();

            $sellerIds = $this->getSellerIdsForUser($user, $request, $companyId)->all();

            // Filtro por vendedor si se recibe seller_id
            $sellerIdParam = $request->input('seller_id');
            if ($sellerIdParam) {
                if (!is_numeric($sellerIdParam)) {
                    $resolvedSeller = Seller::where('uuid', $sellerIdParam)->first();
                    $sellerIdParam = $resolvedSeller ? $resolvedSeller->id : null;
                }
                $sellerIds = $sellerIdParam ? [$sellerIdParam] : [];
            }

            if (empty($sellerIds)) {
                return $this->successResponse(['success' => true, 'data' => []]);
            }

            // JOIN-based calculations
            $lastLiquidation = Liquidation::whereIn('seller_id', $sellerIds)
                ->whereDate('date', $startOfWeekDate)
                ->orderBy('date', 'asc')
                ->first();
            $initialCash = $lastLiquidation ? (float) $lastLiquidation->real_to_deliver : 0.0;

            $income = (float) Income::join('sellers', 'incomes.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->whereBetween('incomes.created_at', [$startOfWeekUtc, $endOfWeekUtc])
                ->sum('incomes.value');

            $cashPayments = (float) Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereBetween('payments.created_at', [$startOfWeekUtc, $endOfWeekUtc])
                ->sum('payments.amount');

            $newCredits = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereRaw('COALESCE(imported_at, created_at) BETWEEN ? AND ?', [$startOfWeekUtc, $endOfWeekUtc])
                ->whereNull('renewed_from_id')
                ->sum('credit_value');

            $expenses = (float) Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->whereBetween('expenses.created_at', [$startOfWeekUtc, $endOfWeekUtc])
                ->sum('expenses.value');

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
        } catch (\Throwable $e) {
            \Log::error("Error loading weekly financial summary: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return $this->errorResponse('Error al obtener el balance general semanal: ' . $e->getMessage(), 500);
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

            $startDateString = $start->toDateString();
            $endDateString = $end->toDateString();

            // 2. SELLER FILTERING
            $sellerIds = $this->getSellerIdsForUser($user, $request, $companyId)->all();
            
            $sellerIdParam = $request->input('seller_id');
            if ($sellerIdParam) {
                if (!is_numeric($sellerIdParam)) {
                    $resolvedSeller = Seller::where('uuid', $sellerIdParam)->first();
                    $sellerIdParam = $resolvedSeller ? $resolvedSeller->id : null;
                }
                $sellerIds = $sellerIdParam ? [$sellerIdParam] : [];
            }

            if (empty($sellerIds)) {
                return $this->successResponse(['success' => true, 'data' => []]);
            }

            // 3. GLOBAL AGGREGATIONS (Using JOINs instead of whereIn(credit_ids/user_ids))
            
            // Income Total
            $income = (float) Income::join('sellers', 'incomes.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->where(fn($q) => $q->whereBetween('incomes.business_date', [$startDateString, $endDateString])->orWhereBetween('incomes.created_at', [$start, $end]))
                ->sum('incomes.value');

            // Expenses Total
            $expenses = (float) Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->where(fn($q) => $q->whereBetween('expenses.business_date', [$startDateString, $endDateString])->orWhereBetween('expenses.created_at', [$start, $end]))
                ->sum('expenses.value');

            // Collected Total
            $collected = (float) Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->where(fn($q) => $q->whereBetween('payments.business_date', [$startDateString, $endDateString])->orWhereBetween('payments.created_at', [$start, $end]))
                ->sum('payments.amount');

            $newCredits = (float) Credit::whereIn('seller_id', $sellerIds)
                ->whereBetween('created_at', [$start, $end])
                ->whereNull('renewed_from_id')
                ->sum('credit_value');

            // Profit Calculation (Simplified global) - Using PaymentInstallment JOIN for speed
            $totalCapitalPaid = (float) PaymentInstallment::join('installments', 'payment_installments.installment_id', '=', 'installments.id')
                ->join('credits', 'installments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereBetween('payment_installments.created_at', [$start, $end])
                ->sum('payment_installments.applied_amount');

            $profit = max(0, $collected - $totalCapitalPaid);

            // 4. BREAKDOWN BY SELLER (Optimized using GROUP BY)
            $sellers = Seller::whereIn('id', $sellerIds)->with('user:id,name')->get();
            
            $incomeMap = Income::join('sellers', 'incomes.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->whereBetween('incomes.created_at', [$start, $end])
                ->select('sellers.id as seller_id', DB::raw('SUM(incomes.value) as total'))
                ->groupBy('sellers.id')
                ->pluck('total', 'seller_id');

            $expenseMap = Expense::join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
                ->whereIn('sellers.id', $sellerIds)
                ->whereBetween('expenses.created_at', [$start, $end])
                ->select('sellers.id as seller_id', DB::raw('SUM(expenses.value) as total'))
                ->groupBy('sellers.id')
                ->pluck('total', 'seller_id');

            $collectedMap = Payment::join('credits', 'payments.credit_id', '=', 'credits.id')
                ->whereIn('credits.seller_id', $sellerIds)
                ->whereBetween('payments.created_at', [$start, $end])
                ->select('credits.seller_id', DB::raw('SUM(payments.amount) as total'))
                ->groupBy('credits.seller_id')
                ->pluck('total', 'credits.seller_id');

            $sellerBreakdown = [];
            foreach ($sellers as $seller) {
                $sIncome = (float) ($incomeMap->get($seller->id) ?? 0);
                $sExpenses = (float) ($expenseMap->get($seller->id) ?? 0);
                $sCollected = (float) ($collectedMap->get($seller->id) ?? 0);

                if ($sIncome > 0 || $sExpenses > 0 || $sCollected > 0) {
                    $sellerBreakdown[] = [
                        'seller_id' => $seller->id,
                        'name' => $seller->user?->name ?? 'Vendedor sin nombre',
                        'income' => (float) number_format($sIncome, 2, '.', ''),
                        'expenses' => (float) number_format($sExpenses, 2, '.', ''),
                        'collected' => (float) number_format($sCollected, 2, '.', ''),
                    ];
                }
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
        } catch (\Throwable $e) {
            \Log::error("Error loading weekly movements: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return $this->errorResponse('Error al obtener movimientos: ' . $e->getMessage(), 500);
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
            $query = Income::with('user');
            
            $query->where(function($q) use ($start, $end) {
                $q->whereBetween('incomes.business_date', [$start->toDateString(), $end->toDateString()])
                  ->orWhereBetween('incomes.created_at', [$start, $end]);
            });

            if ($sellerId) {
                if (!is_numeric($sellerId)) {
                    $resolvedSeller = Seller::where('uuid', $sellerId)->first();
                    $sellerId = $resolvedSeller ? $resolvedSeller->id : null;
                }
                $seller = Seller::find($sellerId);
                if ($seller && $seller->user_id) {
                    $query->where('incomes.user_id', $seller->user_id);
                } else {
                    $query->whereRaw('0 = 1');
                }
            } elseif (!empty($sellerIds)) {
                $query->join('sellers', 'incomes.user_id', '=', 'sellers.user_id')
                      ->whereIn('sellers.id', $sellerIds);
            }

            $incomes = $query->orderBy('incomes.business_date', 'asc')
                             ->orderBy('incomes.created_at', 'asc')
                             ->select('incomes.*') // Avoid column collisions with join
                             ->get();

            $grouped = [];
            foreach ($incomes as $income) {
                $date = $income->business_date ? $income->business_date->toDateString() : $income->created_at->format('Y-m-d');
                if (!isset($grouped[$date]))
                    $grouped[$date] = [];
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
            $query = Expense::with('user');

            $query->where(function($q) use ($start, $end) {
                $q->whereBetween('expenses.business_date', [$start->toDateString(), $end->toDateString()])
                  ->orWhereBetween('expenses.created_at', [$start, $end]);
            });

            if ($sellerId) {
                if (!is_numeric($sellerId)) {
                    $resolvedSeller = Seller::where('uuid', $sellerId)->first();
                    $sellerId = $resolvedSeller ? $resolvedSeller->id : null;
                }
                $seller = Seller::find($sellerId);
                if ($seller && $seller->user_id) {
                    $query->where('expenses.user_id', $seller->user_id);
                } else {
                    $query->whereRaw('0 = 1');
                }
            } elseif (!empty($sellerIds)) {
                $query->join('sellers', 'expenses.user_id', '=', 'sellers.user_id')
                      ->whereIn('sellers.id', $sellerIds);
            }

            $expenses = $query->orderBy('expenses.business_date', 'asc')
                              ->orderBy('expenses.created_at', 'asc')
                              ->select('expenses.*')
                              ->get();

            $grouped = [];
            foreach ($expenses as $expense) {
                $date = $expense->business_date ? $expense->business_date->toDateString() : $expense->created_at->format('Y-m-d');
                if (!isset($grouped[$date]))
                    $grouped[$date] = [];
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
                $query = Payment::with(['credit.seller', 'credit.seller.user', 'credit.client']);
                
                if ($sellerId) {
                    if (!is_numeric($sellerId)) {
                        $resolvedSeller = Seller::where('uuid', $sellerId)->first();
                        $sellerId = $resolvedSeller ? $resolvedSeller->id : null;
                    }
                    $query->join('credits', 'payments.credit_id', '=', 'credits.id')
                          ->where('credits.seller_id', $sellerId);
                } elseif (!empty($sellerIds)) {
                    $query->join('credits', 'payments.credit_id', '=', 'credits.id')
                          ->whereIn('credits.seller_id', $sellerIds);
                }
                
                $payments = $query->where(function($q) use ($start, $end) {
                        $q->whereBetween('payments.business_date', [$start->toDateString(), $end->toDateString()])
                          ->orWhereBetween('payments.created_at', [$start, $end]);
                    })
                    ->where('payments.amount', '>', 0)
                    ->orderBy('payments.business_date', 'asc')
                    ->orderBy('payments.created_at', 'asc')
                    ->select('payments.*')
                    ->get();
                
                $grouped = [];
                foreach ($payments as $payment) {
                    $date = $payment->business_date ? $payment->business_date->toDateString() : $payment->created_at->format('Y-m-d');
                    if (!isset($grouped[$date]))
                        $grouped[$date] = [];
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
        } catch (\Throwable $e) {
            \Log::error("Error loading movements history: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return $this->errorResponse('Error al obtener histórico de movimientos: ' . $e->getMessage(), 500);
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
