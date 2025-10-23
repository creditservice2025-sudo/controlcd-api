<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    use ApiResponse;
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function loadDahsboardData(Request $request)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->dashboardService->loadCounters($request, $companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getPendingPortfolios(Request $request)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->dashboardService->loadPendingPortfolios($request, $companyId);
        } catch (\Exception $e) {
            \Log::error("Error in getPendingPortfolios: " . $e->getMessage());
            return $this->errorResponse('Error al obtener las carteras pendientes.', 500);
        }
    }

    public function loadFinancialSummary(Request $request)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->dashboardService->loadFinancialSummary($request, $companyId);
        } catch (\Exception $e) {
            \Log::error("Error in loadFinancialSummary: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el resumen financiero.', 500);
        }
    }

    public function loadWeeklyMovements(Request $request)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->dashboardService->weeklyMovements($request, $companyId);
        } catch (\Exception $e) {
            \Log::error("Error in loadFinancialSummary: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el resumen financiero.', 500);
        }
    }

    public function loadWeeklyMovementsHistory(Request $request)
    {
        try {
            $companyId = $request->input('company_id');
            $sellerId = $request->input('seller_id');
            return $this->dashboardService->weeklyMovementsHistory($request, $sellerId, $companyId);
        } catch (\Exception $e) {
            \Log::error("Error in loadWeeklyMovementsHistory: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el histórico de movimientos.', 500);
        }
    }

    public function weeklyFinancialSummary(Request $request)
    {
        try {
            $companyId = $request->input('company_id');
            return $this->dashboardService->weeklyFinancialSummary($request, $companyId);
        } catch (\Exception $e) {
            \Log::error("Error in weeklyFinancialSummary: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el resumen financiero.', 500);
        }
    }
}
