<?php

namespace App\Services;

use App\Http\Requests\City\CityRequest;
use App\Traits\ApiResponse;
use App\Models\City;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function getSellersByCity($city_id = null, Request $request, $companyId = null)
    {
        try {
            $search = $request->query('search', '');
            $user = Auth::user();
            $seller = $user->seller;
            $company = $user->company;

            $query = Seller::with('user');
            if (!empty($city_id)) {
                $query->where('city_id', $city_id);
            }

            \Log::info("Getting sellers for city_id: " . ($city_id ?? 'all') . ", companyId: " . ($companyId ?? 'none') . ", user role: " . $user->role_id);

            // FILTRO POR company_id SIEMPRE QUE EXISTA
            if ($companyId) {
                $query->where('company_id', $companyId);
            }

            // === FILTRO POR ROL ===
            switch ($user->role_id) {
                case 1: // Admin: ve todos los vendedores
                    break;
                case 2: // Empresa: solo vendedores de la empresa
                    if (!$companyId && $company) {
                        $query->where('company_id', $company->id);
                    }
                    break;
                case 3: // Supervisor/Vendedor: solo su propio registro
                    if ($seller) {
                        $query->where('id', $seller->id);
                    } else {
                        $query->whereRaw('0 = 1');
                    }
                    break;
                default: // Otros roles: no ven nada
                    $query->whereRaw('0 = 1');
                    break;
            }

            if (!empty($search)) {
                $query->where('name', 'LIKE', "%{$search}%");
            }

            return response()->json([
                'success' => true,
                'data' => $query->get()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo vendedores: ' . $e->getMessage()
            ], 500);
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
