<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Http\Requests\Company\CompanyRequest;
use App\Traits\ApiResponse;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Log;
use Illuminate\Support\Str;

class CompanyService
{
    use ApiResponse;

    public function index(
        string $search = '',
        int $perPage = 10,
        string $orderBy = 'created_at',
        string $orderDirection = 'desc'
    ) {
        try {
            $companies = Company::with('user')
                ->withCount(['sellers'])
                ->withSum('credits as total_credits_value', 'credit_value')
                ->with(['credits' => function ($query) {
                    $query->select('company_id', 'total_interest');
                }])
                ->when($search, function ($query, $search) {
                    return $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('ruc', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('dni', 'like', "%{$search}%");
                        });
                })
                ->orderBy($orderBy, $orderDirection)
                ->paginate($perPage);

            $transformedCompanies = $companies->getCollection()->transform(function ($company) {
                $totalInterest = $company->credits->sum('total_interest');

                $company->total_with_interest = $company->total_credits_value +
                    ($company->total_credits_value * $totalInterest / 100);

                $company->credits_count = $company->credits->count();

                return $company;
            });

            $companies->setCollection($transformedCompanies);

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresas obtenidas exitosamente',
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al obtener las empresas', 500);
        }
    }
    public function create(CompanyRequest $request)
    {
        DB::beginTransaction();

        try {
            $params = $request->validated();

            if ($request->hasFile('logo')) {
                $validationResponse = $this->validateLogo($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
            }

            $user = User::create([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'phone' => $params['phone'] ?? null,
                'password' => Hash::make($params['password']),
                'role_id' => $params['role_id'] ?? 2
            ]);

            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = Helper::uploadFile($request->file('logo'), 'companies/logos');
            }

            $company = Company::create([
                'user_id' => $user->id,
                'code' => $params['code'],
                'ruc' => $params['ruc'],
                'name' => $params['company_name'],
                'phone' => $params['company_phone'] ?? '',
                'email' => $params['company_email'],
                'logo_path' => $logoPath
            ]);

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresa creada con éxito',
                'data' => $company
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating company: ' . $e->getMessage());
            return $this->errorResponse('Error al crear la empresa', 500);
        }
    }

    public function update($companyId, CompanyRequest $request)
    {
        DB::beginTransaction();

        try {
            $params = $request->validated();
            $company = Company::with('user')->findOrFail($companyId);

            $company->user->update([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'phone' => $params['phone'] ?? $company->user->phone,
                'password' => isset($params['password']) ? Hash::make($params['password']) : $company->user->password
            ]);

            if ($request->hasFile('logo')) {
                if ($company->logo_path) {
                    Helper::deleteFile($company->logo_path);
                }

                $logoPath = Helper::uploadFile($request->file('logo'), 'companies/logos');
                $params['logo_path'] = $logoPath;
            }

            $company->update([
                'code' => $params['code'],
                'ruc' => $params['ruc'],
                'name' => $params['company_name'],
                'phone' => $params['company_phone'] ?? $company->phone,
                'email' => $params['company_email'],
                'logo_path' => $params['logo_path'] ?? $company->logo_path
            ]);

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Empresa actualizada con éxito",
                'data' => $company->load('user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating company: ' . $e->getMessage());
            return $this->errorResponse('Error al actualizar la empresa', 500);
        }
    }

    private function validateLogo($request)
    {
        $logo = $request->file('logo');

        if (!$logo instanceof UploadedFile) {
            return $this->errorResponse('El logo debe ser un archivo válido.', 400);
        }

        if ($logo->getSize() > 2 * 1024 * 1024) {
            return $this->errorResponse("El logo excede el tamaño máximo de 2MB", 400);
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
        if (!in_array($logo->getMimeType(), $allowedMimeTypes)) {
            return $this->errorResponse('El formato del logo no es válido. Use JPEG, PNG, GIF o SVG.', 400);
        }

        return true;
    }

    public function delete($companyId)
    {
        DB::beginTransaction();

        try {
            $company = Company::with('user')->find($companyId);

            if ($company == null) {
                return $this->errorNotFoundResponse('Empresa no encontrada');
            }

            if ($company->sellers()->exists()) {
                return $this->errorResponse('No se puede eliminar la empresa porque tiene vendedores asociados', 422);
            }

            if ($company->logo_path) {
                Helper::deleteFile($company->logo_path);
            }

            $user = $company->user;
            $company->delete();
            $user->delete();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => "Empresa eliminada con éxito"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->errorResponse('Error al eliminar la empresa', 500);
        }
    }
}
