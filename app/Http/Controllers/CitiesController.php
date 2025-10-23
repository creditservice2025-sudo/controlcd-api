<?php

namespace App\Http\Controllers;

use App\Http\Requests\City\CityRequest;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\CitiesService;
use Exception;

class CitiesController extends Controller
{
    use ApiResponse;

    private $citiesService;

    public function __construct(CitiesService $citiesService)
    {
        $this->citiesService = $citiesService;
    }

    public function getCitiesSelect()
    {
        try {
            return $this->citiesService->getCitiesSelect();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request)
    {
        try {

            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;

            return $this->citiesService->getCities($search, $perPage);
        } catch (Exception $e) {
            return $this->handlerException($e->getMessage());
        }
    }

    public function store(CityRequest $request)
    {
        try {
            return $this->citiesService->store($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(CityRequest $request, $id)
    {
        try {
            return $this->citiesService->update($request, $id);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    public function destroy($id)
    {
        try {
            return $this->citiesService->delete($id);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getByCountry(Request $request, $country_id)
    {
        try {
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;

            return $this->citiesService->getCitiesByCountry(
                $country_id,
                $search,
                $perPage
            );
        } catch (Exception $e) {
            return $this->handlerException($e->getMessage());
        }
    }

    public function getByCities(Request $request, $city_id = null)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->citiesService->getSellersByCity(
                $city_id,
                $request,
                $companyId
            );
        } catch (Exception $e) {
            return $this->handlerException($e->getMessage());
        }
    }
}
