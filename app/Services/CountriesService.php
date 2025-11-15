<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Country;
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
                ['id', 'name', 'currency'],
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


    public function getCountries($withSellerCities = false)
    {
        try {
            $withSellerCities = filter_var($withSellerCities, FILTER_VALIDATE_BOOLEAN);

           /*  \Log::info('Fetching countries with cities.', ['withSellerCities' => $withSellerCities]); */
            if ($withSellerCities) {
               /*  \Log::info('Fetching countries with cities that have sellers.'); */
                $countries = Country::whereHas('cities', function ($cityQuery) {
                    $cityQuery->whereHas('sellers');
                })
                    ->select('id', 'name')
                    ->get();
            } else {
                /* \Log::info('Fetching all countries without filtering by seller cities.'); */
                $countries = Country::select('id', 'name')->get();
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
            return $this->successResponse($country, 'País creado exitosamente', 201);
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
            return $this->successResponse($country, 'País actualizado exitosamente');
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al actualizar el país');
        }
    }
}
