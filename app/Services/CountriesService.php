<?php

namespace App\Services;

use App\Traits\ApiResponse;
use App\Models\Country;
use Illuminate\Support\Facades\Validator;

class CountriesService
{
    use ApiResponse;

    public function getCountries()
    {
        try {
            $countries = Country::select('id', 'name')->get();
            return $this->successResponse($countries);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $this->handlerException('Error al obtener los países');
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
                'name' => 'sometimes|string|max:255|unique:countries,name,'.$id
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