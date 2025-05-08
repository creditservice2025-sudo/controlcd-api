<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\City;

class CitiesService
{
    use ApiResponse;

    public function getCitiesSelect()
    {
        try {
            $routes = City::select('id', 'name')->get();

            return $this->successResponse([
                'success' => true,
                'data' => $routes
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener las rutas');
        }
    }
}
