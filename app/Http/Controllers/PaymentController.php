<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PaymentService;
use App\Http\Requests\Payment\PaymentRequest;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function create(PaymentRequest $request)
    {
        try {
            return $this->paymentService->create($request);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request, $creditId)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('perPage', 10);

            return $this->paymentService->index($creditId, $request, $page, $perPage);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($creditId, $paymentId)
    {
        try {
            return $this->paymentService->show($creditId, $paymentId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($paymentId)
    {
        try {
            return $this->paymentService->delete($paymentId);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
