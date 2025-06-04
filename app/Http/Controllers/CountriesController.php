<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\CountriesService;

class CountriesController extends Controller
{
    use ApiResponse;

    private $countriesService;

    public function __construct(CountriesService $countriesService) {
        $this->countriesService = $countriesService;
    }

    public function index()
    {
        try {
            return $this->countriesService->getCountries();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('perPage', 10);
            $search = $request->get('search', null);

            return $this->countriesService->getAll($page, $perPage, $search);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            return $this->countriesService->store($request->all());
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            return $this->countriesService->update($request->all(), $id);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}