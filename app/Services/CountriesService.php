<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Country;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CountriesService
{
    use ApiResponse;

    public function getAll($page = 1, $perPage = 10, $search = null)
    {
        try {
            $query = Country::query();

            if ($search) {
                $searchTerm = Str::lower($search);
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"]);
            }

            $countries = $query->paginate(
                $perPage,
                ['id', 'name', 'currency', 'status'],
                'page',
                $page
            );

            return $this->successResponse([
                'data' => $countries->items(),
                'pagination' => [
                    'total' => $countries->total(),
                    'current_page' => $countries->currentPage(),
                    'per_page' => $countries->perPage(),
                    'last_page' => $countries->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener los países');
        }
    }


    /**
     * Países activos. Con `$withSellerCities` solo los que tienen RUTAS
     * HABILITADAS PARA LA EMPRESA de quien pregunta.
     *
     * Antes el filtro era "países con alguna ciudad con algún vendedor", sin
     * mirar de qué empresa era ese vendedor: al dar de alta un usuario, el
     * selector "País de la ruta" ofrecía países donde la empresa no tiene una
     * sola ruta. Medido: seis países para todos, cuando una de las empresas no
     * tenía ningún vendedor. Quien elegía uno de esos países se encontraba
     * después con la lista de ciudades vacía y sin entender por qué.
     *
     * El Super-Admin sin empresa en contexto sigue viendo todos: es el único
     * que trabaja sobre varias empresas a la vez. Si está impersonando una
     * empresa, se filtra por esa (`$companyId`), igual que en el resto de las
     * pantallas.
     */
    public function getCountries($withSellerCities = false, $companyId = null)
    {
        try {
            $withSellerCities = filter_var($withSellerCities, FILTER_VALIDATE_BOOLEAN);

            if ($withSellerCities) {
                $user = Auth::user();

                // Empresa a la que pertenecen las rutas que se pueden elegir:
                // la impersonada si viene, si no la del propio usuario. Para el
                // Super-Admin sin contexto de empresa queda null = sin filtro.
                $empresaId = $companyId ?: (($user && $user->role_id !== 1 && $user->company)
                    ? $user->company->id
                    : null);

                $countries = Country::where('status', 'ACTIVE')
                    ->whereHas('cities', function ($cityQuery) use ($empresaId) {
                        $cityQuery->whereHas('sellers', function ($sellerQuery) use ($empresaId) {
                            if ($empresaId) {
                                $sellerQuery->where('company_id', $empresaId);
                            }
                        });
                    })
                    ->select('id', 'name', 'phone_code')
                    ->get();
            } else {
                /* \Log::info('Fetching all countries without filtering by seller cities.'); */
                // phone_code viaja con el país: lo usa el enlace de WhatsApp para
                // armar el número internacional. Es aditivo, quien solo pedía
                // id/name lo ignora.
                $countries = Country::where('status', 'ACTIVE')->select('id', 'name', 'phone_code')->get();
            }
            return $this->successResponse($countries);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al obtener los países');
        }
    }

    public function store($data)
    {
        try {
            $validator = Validator::make($data, [
                'name' => 'required|string|max:255|unique:countries'
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $country = Country::create($data);
            return $this->successCreatedResponse($country);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al crear el país');
        }
    }

    public function update($data, $id)
    {
        try {
            $validator = Validator::make($data, [
                'name' => 'sometimes|string|max:255|unique:countries,name,' . $id
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $country = Country::findOrFail($id);
            $country->update($data);
            return $this->successResponse($country);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al actualizar el país');
        }
    }
}
