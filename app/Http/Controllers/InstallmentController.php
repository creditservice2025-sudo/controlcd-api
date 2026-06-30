<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\InstallmentService;
use App\Traits\ApiResponse;

class InstallmentController extends Controller
{
    protected $installmentService;

    public function __construct(InstallmentService $installmentService)
    {
        $this->installmentService = $installmentService;
    }
    
    public function index()
    {
        try {
            return $this->installmentService->index();
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }   
    }

    public function show($creditId)
    {
        try {
            return $this->installmentService->show($creditId);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get all installments for a seller with liquidation info
     */
    public function getInstallmentsBySeller(Request $request, $sellerId)
    {
        try {
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search', '');
            $status = $request->input('status', null);

            return $this->installmentService->getInstallmentsBySeller($sellerId, $perPage, $search, $status);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Simulate the deletion of an installment
     */
    public function simulateDelete(Request $request, $installmentId)
    {
        try {
            return $this->installmentService->simulateDelete($installmentId);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an installment and recalculate affected liquidations
     */
    public function deleteInstallment(Request $request, $installmentId)
    {
        \App\Support\Tenant::assertInstallmentInScope($installmentId);
        try {
            $request->validate([
                'password' => 'required|string'
            ]);

            $password = $request->input('password');
            
            return $this->installmentService->deleteInstallment($installmentId, $password);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Contraseña requerida',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get installments for a specific credit
     */
    public function getCreditInstallments(Request $request, $creditId)
    {
        try {
            return $this->installmentService->getCreditInstallments($creditId);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
