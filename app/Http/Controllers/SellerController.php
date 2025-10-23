<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Seller\SellerRequest;
use App\Services\SellerService;
use App\Http\Middleware\RouteAuthMiddleware;

class SellerController extends Controller
{

    use ApiResponse;

    protected $sellerService;

    public function __construct(SellerService $sellerService)
    {
        $this->sellerService = $sellerService;
    }

    public function create(SellerRequest $request)
    {
        try {
            return $this->sellerService->create($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(SellerRequest $request, $sellerId)
    {
        try {
            return $this->sellerService->update($sellerId, $request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function listActiveRoutes(Request $request)
    {
        try {
            $hasLiquidation = $request->get('hasLiquidation');
            $search = $request->get('search');
            $countryId = $request->get('country_id');
            $cityId = $request->get('city_id');
            $sellerId = $request->get('seller_id');
            $companyId = $request->get('company_id');
            return $this->sellerService->listActiveRoutes($hasLiquidation, $search, $countryId, $cityId, $sellerId, $companyId);
        } catch (\Exception $e) {
            \Log::error('Error listing active routes: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($routeId)
    {
        try {
            return $this->sellerService->delete($routeId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;
            $countryId = $request->input('country_id');
            $cityId = $request->input('city_id');
            $companyId = $request->input('company_id');
            return $this->sellerService->getRoutes($page, $perPage, $search, $countryId, $cityId, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getRoutesSelect()
    {
        try {
            return $this->sellerService->getRoutesSelect();
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function toggleStatus(Request $request, $routeId)
    {
        $status = $request->input('status');
        return $this->sellerService->toggleStatus($routeId, $status);
    }
}
