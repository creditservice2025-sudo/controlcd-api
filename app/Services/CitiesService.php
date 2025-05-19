<?php

namespace App\Services;

use App\Http\Requests\City\CityRequest;
use App\Traits\ApiResponse;
use App\Models\City;
use Illuminate\Support\Facades\DB;

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
            DB::beginTransaction();
            $params = $request->validated();

            $city = City::create($params);
            DB::commit();
            
            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudad creada con éxito',
                'data' => $city
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al crear la ciudad', 500);
        }
    }

    public function update(CityRequest $request, $id)
    {
        try {
            DB::beginTransaction();
            $params = $request->validated();

            $city = City::findOrFail($id);
            $city->update($params);
            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudad actualizada con éxito',
                'data' => $city
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al actualizar la ciudad', 500);
        }
    }

    public function getCitiesByCountry($country_id, $search = '')
    {
        try {
            $query = City::where('country_id', $country_id)
                ->with('country')
                ->orderBy('name');

            if (!empty($search)) {
                $query->where('name', 'LIKE', "%{$search}%");
            }

            return $this->successResponse($query->get());
        } catch (Exception $e) {
            throw new Exception("Error obteniendo ciudades: " . $e->getMessage());
        }
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();
            $city = City::findOrFail($id);
            $city->delete();
            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Ciudad eliminada exitosamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar la ciudad: ' . $e->getMessage(), 500);
        }
    }
}