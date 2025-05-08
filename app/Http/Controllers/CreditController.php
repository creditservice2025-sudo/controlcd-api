<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CreditService;
use App\Http\Requests\Credit\CreditRequest;
use App\Traits\ApiResponse;

class CreditController extends Controller
{
    use ApiResponse;

    protected $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->creditService = $creditService;
    }

    public function create(CreditRequest $request)
    {
        try {
            return $this->creditService->create($request);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(CreditRequest $request, $creditId)
    {
        try {
            return $this->creditService->update($request, $creditId);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($id)
    {
        try {
            return $this->creditService->delete($id);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(CreditRequest $request)
    {
        try {
            return $this->creditService->index($request);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            return $this->creditService->show($id);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getCreditsSelect(string $search = '')
    {
        try {
            return $this->creditService->getCreditsSelect($search);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClientCredits(Request $request){
        try {

            $search = $request->get('search') ?? '';
            $perPage = $request->get('perPage') ?? 10;

            return $this->creditService->getClientCredits($search, $perPage);
        } catch (Exception $e) {
            return $this->handlerException($e->getMessage());
        }
    }

}
