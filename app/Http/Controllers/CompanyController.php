<?php

namespace App\Http\Controllers;

use App\Http\Requests\Company\CompanyRequest;
use App\Http\Requests\Company\CompanyCodeRequest;
use App\Http\Requests\Company\CompanyRucRequest;
use App\Models\Company;
use App\Services\CompanyService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    use ApiResponse;

    protected $companyService;

    public function __construct(CompanyService $companyService)
    {
        $this->companyService = $companyService;
    }

    public function create(CompanyRequest $request)
    {
        try {
            return $this->companyService->create($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(CompanyRequest $request, $companyId)
    {
        try {
            return $this->companyService->update($companyId, $request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($companyId)
    {
        try {
            return $this->companyService->delete($companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

  public function index(Request $request)
{
    try {
        $search = $request->input('search', '');
        $perPage = $request->input('perPage', 10);
        $orderBy = $request->input('orderBy', 'created_at');
        $orderDirection = $request->input('orderDirection', 'desc');

        return $this->companyService->index($search, $perPage, $orderBy, $orderDirection);
    } catch (\Exception $e) {
        return $this->errorResponse($e->getMessage(), 500);
    }
}

    public function show($companyId)
    {
        try {
            $company = Company::with('user')->findOrFail($companyId);
            
            return $this->successResponse([
                'success' => true,
                'message' => 'Empresa obtenida exitosamente',
                'data' => $company
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getCompaniesSelect(Request $request)
    {
        try {
            $search = $request->input('search', '');
            
            $companies = Company::when($search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                             ->orWhere('code', 'like', "%{$search}%");
            })
            ->select('id', 'name', 'code')
            ->limit(20)
            ->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresas para selección obtenidas exitosamente',
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function validateCompanyCode(CompanyCodeRequest $request)
    {
        return $this->successResponse([
            'success' => true,
            'message' => 'Código de empresa válido'
        ]);
    }

    public function validateCompanyRuc(CompanyRucRequest $request)
    {
        return $this->successResponse([
            'success' => true,
            'message' => 'RUC válido'
        ]);
    }

}