<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Route\RouteRequest;
use App\Services\RouteService;
use App\Http\Middleware\RouteAuthMiddleware;

class RouteController extends Controller
{

    use ApiResponse;

    protected $routeService;

    public function __construct(RouteService $routeService)
    {
        $this->routeService = $routeService;
    }

    public function create(RouteRequest $request)
    {
        try {
            $params = $request->all();

            return $this->routeService->create($params);

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(Request $request, $routeId)
    {
        try {

            $params = $request->all();

            return $this->routeService->update($routeId, $params);

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($routeId)
    {
        try {
            return $this->routeService->delete($routeId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request)
    {
        try {

            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;

            return $this->routeService->getRoutes($search, $perPage);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getRoutesSelect(){
        try {
            return $this->routeService->getRoutesSelect();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function toggleStatus(Request $request, $routeId)
    {
        $status = $request->input('status');
        return $this->routeService->toggleStatus($routeId, $status);
    }

}
