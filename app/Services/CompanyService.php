<?php

namespace App\Services;

use App\Helpers\Helper;
use App\Http\Requests\Company\CompanyRequest;
use App\Mail\WelcomeCompanyMail;
use App\Traits\ApiResponse;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
                ->withCount(['sellers', 'credits'])
                ->withSum('credits as total_credits_value', 'credit_value')
                ->withSum('credits as total_interest_sum', 'total_interest')
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
                $totalInterest = $company->total_interest_sum ?? 0;
                $totalCreditsValue = $company->total_credits_value ?? 0;

                $company->total_with_interest = $totalCreditsValue +
                    ($totalCreditsValue * $totalInterest / 100);

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

            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['created_at'] = \Carbon\Carbon::now($params['timezone']);
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            if ($request->hasFile('logo')) {
                $validationResponse = $this->validateLogo($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
            }

            // Capture plain password before hashing (needed for welcome email)
            // If password is not provided, generate a random one
            $plainPassword = $params['password'] ?? Str::random(8);

            $user = User::create([
                'name'                 => $params['name'],
                'email'                => $params['email'],
                'dni'                  => $params['dni'],
                'phone'                => $params['phone'] ?? null,
                'password'             => Hash::make($plainPassword),
                'role_id'              => $params['role_id'] ?? 2,
                'must_change_password' => true,   // force password change on first login
                'created_at'           => $params['created_at'] ?? null,
                'updated_at'           => $params['updated_at'] ?? null
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
                'logo_path' => $logoPath,
                'is_financing_enabled' => $params['is_financing_enabled'] ?? true,
                'is_collection_enabled' => $params['is_collection_enabled'] ?? false,
                'created_at' => $params['created_at'] ?? null,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            DB::commit();

            // Send welcome email using queue to avoid blocking the HTTP response.
            // Mail::later dispatches to queue (sync driver = defers after response).
            try {
                \Log::info('Attempting to send welcome email (create)', [
                    'from' => config('mail.from.address'),
                    'to'   => $user->email
                ]);
                Mail::to($user->email)->later(
                    now(),
                    new WelcomeCompanyMail($user, $company, $plainPassword, 'welcome')
                );
            } catch (\Throwable $mailEx) {
                Log::warning('Welcome email could not be sent to ' . $user->email . ': ' . $mailEx->getMessage());
            }

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresa creada con éxito',
                'data'    => $company
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating company: ' . $e->getMessage(), [
                'params' => $request->all(),
                'trace'  => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Error al crear la empresa: ' . $e->getMessage(), 500);
        }
    }

    public function update($companyId, CompanyRequest $request)
    {
        DB::beginTransaction();

        try {
            $params = $request->validated();
            $company = Company::with('user')->findOrFail($companyId);

            // Si se recibe timezone, usarlo para la hora local en updated_at
            if (isset($params['timezone']) && !empty($params['timezone'])) {
                $params['updated_at'] = \Carbon\Carbon::now($params['timezone']);
                $userTimezone = $params['timezone'];
                unset($params['timezone']);
            } else {
                $userTimezone = null;
            }

            $company->user->update([
                'name' => $params['name'],
                'email' => $params['email'],
                'dni' => $params['dni'],
                'phone' => $params['phone'] ?? $company->user->phone,
                'password' => isset($params['password']) ? Hash::make($params['password']) : $company->user->password,
                'updated_at' => $params['updated_at'] ?? null
            ]);

            if ($request->hasFile('logo')) {
                $validationResponse = $this->validateLogo($request);
                if ($validationResponse !== true) {
                    return $validationResponse;
                }
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
                'logo_path' => $params['logo_path'] ?? $company->logo_path,
                'is_financing_enabled' => $params['is_financing_enabled'] ?? $company->is_financing_enabled,
                'is_collection_enabled' => $params['is_collection_enabled'] ?? $company->is_collection_enabled,
                'updated_at' => $params['updated_at'] ?? null
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

    /**
     * Toggles a specific module for a company.
     *
     * @param int $companyId
     * @param string $module (financing|collection)
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function toggleModule($companyId, $module)
    {
        try {
            $company = Company::findOrFail($companyId);
            
            $field = ($module === 'financing') ? 'is_financing_enabled' : 'is_collection_enabled';
            
            // Toggle the current state
            $company->$field = !$company->$field;
            $company->save();

            $moduleName = ($module === 'financing') ? 'Control CD' : 'Deuda & Abono';
            $stateText = $company->$field ? 'activado' : 'desactivado';

            return $this->successResponse([
                'success' => true,
                'message' => "Módulo {$moduleName} {$stateText} con éxito",
                'data' => $company->load('user')
            ]);
        } catch (\Exception $e) {
            \Log::error("Error toggling module {$module} for company {$companyId}: " . $e->getMessage());
            return $this->errorResponse("Error al cambiar estado del módulo", 500);
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

    public function delete($companyId, $timezone = null)
    {
        DB::beginTransaction();

        try {
            $company = Company::with('user')->find($companyId);

            if ($company == null) {
                DB::rollBack();
                return $this->errorNotFoundResponse('Empresa no encontrada');
            }

            if ($company->sellers()->exists()) {
                DB::rollBack();
                return $this->errorResponse('No se puede eliminar la empresa porque tiene vendedores asociados', 422);
            }

            if ($company->logo_path) {
                Helper::deleteFile($company->logo_path);
            }

            $user = $company->user;
            if ($timezone) {
                $company->deleted_at = \Carbon\Carbon::now($timezone);
                $company->save();
                $company->delete();
                if ($user) {
                    $user->deleted_at = \Carbon\Carbon::now($timezone);
                    $user->save();
                    $user->delete();
                }
            } else {
                $company->delete();
                if ($user) {
                    $user->delete();
                }
            }

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

    public function resendWelcomeEmail($companyId, $customPassword = null)
    {
        $company = Company::with('user')->findOrFail($companyId);
        $user = $company->user;

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado para esta empresa', 404);
        }

        // Use custom password if provided, otherwise generate a new random one
        $newPassword = $customPassword ?? Str::random(8);
        
        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => true
        ]);

        try {
            // For resending, we use direct send() instead of later() to give 
            // the admin immediate confirmation that the email was attempted.
            \Log::info('Attempting to send welcome email (resend)', [
                'from' => config('mail.from.address'),
                'to'   => $user->email,
                'company' => $company->name,
                'user_id' => $user->id,
                'password_len' => strlen($newPassword),
                'mailer' => config('mail.mailer'),
                'is_custom' => !is_null($customPassword)
            ]);
            
            Mail::to($user->email)->send(
                new WelcomeCompanyMail($user, $company, $newPassword, 'reset')
            );
            
            \Log::info('Welcome email (resend) sent successfully to ' . $user->email);
        } catch (\Throwable $mailEx) {
            \Log::error('Resend welcome email failed for ' . $user->email . ': ' . $mailEx->getMessage(), [
                'exception' => $mailEx,
                'trace' => $mailEx->getTraceAsString()
            ]);
            return $this->errorResponse('El correo no pudo ser enviado: ' . $mailEx->getMessage(), 500);
        }

        return $this->successResponse([
            'success' => true,
            'message' => 'Correo de bienvenida reenviado con éxito.' . ($customPassword ? '' : ' Se ha generado una nueva contraseña temporal.')
        ]);
    }
}
