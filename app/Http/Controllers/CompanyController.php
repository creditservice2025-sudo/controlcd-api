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
     /*    $this->middleware('permission:ver_empresa')->only('index');
        $this->middleware('permission:crear_empresa')->only('create');
        $this->middleware('permission:editar_empresa')->only('update');
        $this->middleware('permission:eliminar_empresa')->only('delete'); */
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
            $companyId = $request->input('company_id');
            
            $companies = Company::when($search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                             ->orWhere('code', 'like', "%{$search}%");
            })
            ->when($companyId, function ($query, $companyId) {
                return $query->where('id', $companyId);
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

    public function sendVerificationCode(Request $request, \App\Services\WhatsAppService $whatsAppService)
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'company_id' => 'nullable|exists:companies,id'
            ]);

            $phone = $request->input('phone');
            $companyId = $request->input('company_id');
            
            // Si hay ID de empresa, usarlo para guardar el código
            $company = null;
            if ($companyId) {
                $company = Company::find($companyId);
            }

            $code = $whatsAppService->generateVerificationCode();
            
            // Si tenemos la empresa, guardamos el código
            if ($company) {
                $company->last_verification_code = $code;
                $company->verification_code_expires_at = now()->addMinutes(5);
                $company->save();
            }

            // Enviar el código (siempre se intenta enviar)
            // Nota: En producción real, necesitaríamos la API Key del usuario o una global
            // Por ahora usamos la del .env si existe, o simulamos
            $apiKey = env('CALLMEBOT_API_KEY');
            
            if (!$apiKey) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'Modo desarrollo: Código generado (ver logs)',
                    'debug_code' => $code // Solo para desarrollo
                ]);
            }

            $sent = $whatsAppService->sendVerificationCode($phone, $code, $apiKey);

            if ($sent) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'Código enviado correctamente'
                ]);
            } else {
                return $this->errorResponse('Error al enviar el código', 500);
            }

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function verifyCode(Request $request)
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:companies,id',
                'code' => 'required|string'
            ]);

            $company = Company::find($request->input('company_id'));
            $code = $request->input('code');

            if ($company->last_verification_code === $code && 
                $company->verification_code_expires_at > now()) {
                
                $company->whatsapp_verified = true;
                $company->last_verification_code = null;
                $company->verification_code_expires_at = null;
                $company->save();

                return $this->successResponse([
                    'success' => true,
                    'message' => 'WhatsApp verificado correctamente'
                ]);
            }

            return $this->errorResponse('Código inválido o expirado', 400);

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}