<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Liquidation;
use App\Models\Payment;
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

            if ($role === 1 || $role === 2) {
                $data['members'] = User::all()->count();
                $data['routes'] = Seller::all()->count();
                $data['credits'] = Credit::all()->count();
                $data['clients'] = Client::all()->count();
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

    public function loadFinancialSummary(Request $request)
    {
        try {
            $user = Auth::user();
            $role = $user->role_id;
            $today = now()->toDateString();

            $totalBalance = 0;
            $capital = 0;
            $profit = 0;
            $currentCash = 0;

            if ($role === 1 || $role === 2) {
                $totalBalance = Credit::where('status', '!=', 'Liquidado')->sum('credit_value');
                $capital = Credit::sum('credit_value');
                $profit = Credit::sum(DB::raw('credit_value * total_interest / 100'));

                $lastLiquidation = Liquidation::orderBy('date', 'desc')->first();
                $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                $cashPayments = Payment::whereDate('created_at', $today)->sum('amount');

                $expenses = Expense::whereDate('created_at', $today)->sum('value');

                $newCredits = Credit::whereDate('created_at', $today)->sum('credit_value');

                $currentCash = $initialCash + $cashPayments - $expenses - $newCredits;
            } elseif ($role === 5) {
                $seller = $user->seller;

                if ($seller) {
                    $totalBalance = $seller->credits()->where('status', '!=', 'Liquidado')->sum('credit_value');
                    $capital = $seller->credits()->sum('credit_value');
                    $profit = $seller->credits()->sum(DB::raw('credit_value * total_interest / 100'));

                    $lastLiquidation = Liquidation::where('seller_id', $seller->id)
                        ->orderBy('date', 'desc')
                        ->first();

                    $initialCash = $lastLiquidation ? $lastLiquidation->real_to_deliver : 0;

                    $creditIds = $seller->credits()->pluck('id');

                    $cashPayments = 0;
                    if ($creditIds->isNotEmpty()) {
                        $cashPayments = Payment::whereIn('credit_id', $creditIds)
                            ->whereDate('created_at', $today)
                            ->sum('amount');
                    }

                    $expenses = Expense::where('user_id', $user->id)
                        ->whereDate('created_at', $today)
                        ->sum('value');

                    $newCredits = $seller->credits()
                        ->whereDate('created_at', $today)
                        ->sum('credit_value');

                    $currentCash = $initialCash + $cashPayments - $expenses - $newCredits;
                }
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'totalBalance' => (float) number_format($totalBalance, 2, '.', ''),
                    'capital' => (float) number_format($capital, 2, '.', ''),
                    'profit' => (float) number_format($profit, 2, '.', ''),
                    'currentCash' => (float) number_format($currentCash, 2, '.', ''),
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

            if ($role === 1 || $role === 2) {
                $income = Payment::whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('amount');

                $expenses = Expense::whereBetween('created_at', [$startOfWeek, $endOfWeek])
                    ->sum('value');
            } elseif ($role === 5) {
                $seller = $user->seller;

                if ($seller) {
                    $creditIds = $seller->credits()->pluck('id');

                    if ($creditIds->isNotEmpty()) {
                        $income = Payment::whereIn('credit_id', $creditIds)
                            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                            ->sum('amount');
                    }

                    $expenses = Expense::where('user_id', $user->id)
                        ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                        ->sum('value');
                }
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
