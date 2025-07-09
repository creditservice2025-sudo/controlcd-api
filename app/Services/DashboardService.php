<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Credit;
use App\Models\Seller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

            $totalBalance = 0; 
            $capital = 0;      
            $profit = 0;      

            if ($role === 1 || $role === 2) { 
                $totalBalance = Credit::where('status', '!=', 'Saldado')->sum('credit_value'); 
                $capital = Credit::sum('credit_value');
                $profit = Credit::sum(DB::raw('credit_value * total_interest / 100')); 

            } elseif ($role === 5) { 
                $seller = $user->seller;

                if ($seller) {
                    $totalBalance = $seller->credits()->where('status', '!=', 'Saldado')->sum('credit_value');
                    $capital = $seller->credits()->sum('credit_value');
                    $profit = Credit::sum(DB::raw('credit_value * total_interest / 100'));
                }
            } 

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'totalBalance' => (float) number_format($totalBalance, 2, '.', ''),
                    'capital' => (float) number_format($capital, 2, '.', ''),
                    'profit' => (float) number_format($profit, 2, '.', ''),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error("Error loading financial summary: " . $e->getMessage());
            return $this->errorResponse('Error al obtener el resumen financiero.', 500);
        }
    }

}
