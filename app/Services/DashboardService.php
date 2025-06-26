<?php

namespace App\Services;

use App\Models\User;
use App\Models\Credit;
use App\Models\Seller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DashboardService
{
    use ApiResponse;

    public function loadCounters(Request $request)
    {
        try {
            // get data
            $userCount = User::all()->count();
            $routesCount = Seller::all()->count();
            $creditsCount = Credit::all()->count();

            // return response
            return $this->successResponse([
                'success' => true,
                'data' => [
                    'members' => $userCount,
                    'routes' => $routesCount,
                    'credits' => $creditsCount,
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener el conteo inicial');
        }
    }
}
