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
            return $this->dashboardService->loadCounters($request);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getPendingPortfolios(Request $request)
    {
        try {
            return $this->dashboardService->loadPendingPortfolios();
        } catch (Exception $e) {
            \Log::error("Error in getPendingPortfolios: " . $e->getMessage());
            return $this->errorResponse('Error al obtener las carteras pendientes.', 500);
        }
    }

    public function loadFinancialSummary(Request $request)
    {
        try {
            return $this->dashboardService->loadFinancialSummary($request);
        } catch (Exception $e) {
            \Log::error("Error in loadFinancialSummary: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el resumen financiero.', 500);
        }
    }

    public function loadWeeklyMovements(Request $request)
    {
        try {
            return $this->dashboardService->weeklyMovements($request);
        } catch (Exception $e) {
            \Log::error("Error in loadFinancialSummary: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el resumen financiero.', 500);
        }
    }

    public function weeklyFinancialSummary(Request $request)
    {
        try {
            return $this->dashboardService->weeklyFinancialSummary($request);
        } catch (Exception $e) {
            \Log::error("Error in weeklyFinancialSummary: " . $e->getMessage());
            return $this->errorResponse('Error al cargar el resumen financiero.', 500);
        }
    }
}
