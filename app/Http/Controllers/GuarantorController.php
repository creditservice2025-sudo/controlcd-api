<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\GuarantorService;
use App\Http\Requests\Guarantor\GuarantorRequest;

class GuarantorController extends Controller
{
    use ApiResponse;

    protected $guarantorService;

    public function __construct(GuarantorService $guarantorService)
    {
        $this->guarantorService = $guarantorService;
    }

    public function create(GuarantorRequest $request)
    {
        try{
            return $this->guarantorService->create($request);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(GuarantorRequest $request, $guarantorId)
    {
        try{
            return $this->guarantorService->update($request, $guarantorId);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($guarantorId)
    {
        try{
            return $this->guarantorService->delete($guarantorId);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getGuarantorsSelect(string $search = '')
    {
        try{
            return $this->guarantorService->getGuarantorsSelect($search);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function index(Request $request)
    {

        try {
            $search = $request->input('search', ''); 
            $perpage = $request->input('perpage', 10); 

            return $this->guarantorService->index($search, $perpage);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
        // try{
        //     return $this->guarantorService->index($request);
        // } catch (Exception $e) {
        //     return $this->errorResponse($e->getMessage(), 500);
        // }
    }

    public function show($guarantorId)
    {
        try{
            return $this->guarantorService->show($guarantorId);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500); 
        }
    }

}
