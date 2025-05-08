<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\CitiesService;

class CitiesController extends Controller
{
    use ApiResponse;

    private $citiesService;

    public function __construct(CitiesService $citiesService) {
        $this->citiesService = $citiesService;
    }

    public function getCitiesSelect(){
        try {
            return $this->citiesService->getCitiesSelect();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
