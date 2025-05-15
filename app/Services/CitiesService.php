<?php

namespace App\Services;

use App\Http\Requests\City\CityRequest;
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

    public function getCities(string $search, int $perPage)
    {
        try {
            $query = City::with('country');

            if (!empty($search)) {
                $query->where('name', 'like', "%{$search}%");
            }

            $paginator = $query->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudades obtenidas correctamente',
                'data' => $paginator
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener las ciudades');
        }
    }
    public function store(CityRequest $request)
    {
        try {
            $params = $request->validated();

            $city = City::create($params);
            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudad creada con éxito',
                'data' => $city
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear la ciudad', 500);
        }
    }

    public function update(CityRequest $request, $id)
    {
        try {
            $params = $request->validated();

            $city = City::findOrFail($id);
            $city->update($params);

            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudad actualizada con éxito',
                'data' => $city
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar la ciudad', 500);
        }
    }

    public function delete($id)
    {
        try {
            $city = City::findOrFail($id);


            $city->delete();
            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudad eliminada exitosamente',
            ]);

        } catch (\Exception $e) {
            throw new \Exception('Error al eliminar la ciudad: ' . $e->getMessage());
        }
    }
}
