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
}
