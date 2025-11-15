<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Credit;
use Carbon\Carbon;
use App\Traits\ApiResponse;

class InstallmentService
{
    use ApiResponse;
    public function index()
    {
        try {
            return $this->successResponse([
                'success' => true,
                'message' => 'Cuotas obtenidas correctamente',
                'data' => Installment::all()
            ]);
        } catch (Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($creditId)
    {
        try {

            $installments = Installment::where('credit_id', $creditId)->get();

            if ($installments->isEmpty()) {
                return $this->errorResponse('No se encontraron cuotas para el crédito especificado', 404);
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Cuota obtenida correctamente',
                'data' => $installments
            ]);
        } catch (Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
}
