<?php

namespace App\Services;

use App\Models\SessionLog;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Company;
use App\Mail\ResetPassword;
use App\Models\Liquidation;
use Hash;
use Carbon\Carbon;

class LoginService
{

    use ApiResponse;

    public function login($credentials)
    {
        try {
            $validator = Validator::make($credentials, [
                'email' => ['required', 'string'],
                'password' => ['required'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            \Log::info('Intento de inicio de sesión: ' . $credentials['email']);

            $user = User::where('email', 'LIKE', $credentials['email'] . '%')
                ->with('city', 'seller', 'company')
                ->first();

                \Log::info('Usuario encontrado: ' . ($user ? $user->email : 'Ninguno'));

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                return $this->errorResponse(['Los datos introducidos son inválidos, verifica e intenta nuevamente'], 401);
            }

            $seller = $user->seller;
            if ($seller && $user->role_id === 5) {
                // Bloqueo estricto: Si existe CUALQUIER liquidaci├│n para hoy, el vendedor no entra.
                // Se usa America/Lima como zona de referencia para consistencia con el resto del app.
                $todayLima = \Carbon\Carbon::now('America/Lima')->toDateString();
                
                $liquidation = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', $todayLima)
                    ->first();

                if ($liquidation && in_array($liquidation->status, ['approved', 'auto', 'pending'])) {
                    return $this->errorResponse(['Ya cerro la liquidación del día. Si desea reabrir la caja debe contactar al administrador'], 401);
                }
            }

            $token = $user->createToken('USER_AUTH_TOKEN')->accessToken;

            if ($user->token_revoked) {
                $user->update([
                    "token_revoked" => 0
                ]);
            }

            $timezone = request()->has('timezone') ? request()->get('timezone') : null;
            $loginAt = $timezone ? Carbon::now($timezone) : now();
            
            // Get the real IP address from the request
            $realIp = request()->ip();
            
            // Mask IP only if the source IP is in the whitelist
            $maskSourceIps = config('app.mask_source_ips', []);
            $maskedIp = config('app.masked_ip');
            
            // Check if this IP should be masked
            $shouldMask = !empty($maskSourceIps) && 
                          !empty($maskedIp) && 
                          in_array($realIp, $maskSourceIps);
            
            $ipAddress = $shouldMask ? $maskedIp : $realIp;
            
            SessionLog::create([
                'user_id'    => $user->id,
                'login_at'   => $loginAt,
                'ip'         => $ipAddress,
                'user_agent' => request()->header('User-Agent'),
            ]);

            // Auto-Apertura si es vendedor
            if ($seller && ($user->role_id === 5 || $user->role_id === 3)) {
                try {
                    $liquidationService = app(\App\Services\LiquidationService::class);
                    $liquidationService->getOrCreateLiquidation($seller->id, $loginAt->toDateString(), $timezone);
                } catch (\Exception $e) {
                    \Log::error("Error en auto-apertura al login: " . $e->getMessage());
                }
            }

            $company = $user->company instanceof Company
                ? $user->company
                : null;

            // Si el usuario no tiene empresa directa, buscar via seller o perfil Collection
            if (!$company && $user->seller) {
                $company = Company::find($user->seller->company_id);
            }
            if (!$company && $user->is_collection_user) {
                $collectionProfile = \DB::connection('collection_pgsql')
                    ->table('collection_user_profiles')
                    ->where('user_id', $user->id)
                    ->first();
                if ($collectionProfile) {
                    $company = Company::find($collectionProfile->company_id);
                }
            }

            $roles = $user->getRoleNames();

            if ($roles->isEmpty() && !empty($user->role_id)) {
                $roleModel = \Spatie\Permission\Models\Role::find($user->role_id);
                if ($roleModel) {
                    $roles = collect([$roleModel->name]);
                } else {
                    $roleFromTable = \DB::table('roles')->where('id', $user->role_id)->value('name');
                    if ($roleFromTable) {
                        $roles = collect([$roleFromTable]);
                    }
                }
            }

            // Un usuario con is_collection_user siempre ve el módulo Collection
            // aunque la empresa no lo tenga habilitado globalmente (ya tiene perfil)
            $hasCollectionAccess = $company ? (bool) $company->is_collection_enabled : false;
            if (!$hasCollectionAccess && $user->is_collection_user) {
                $hasCollectionAccess = true;
            }

            $availableModules = [
                'controlcd' => $company ? (bool) $company->is_financing_enabled : false,
                'collection' => $hasCollectionAccess,
            ];

            // Rol Collection del usuario (admin, manager, analyst, collector)
            $collectionRole = 'collector';
            if (in_array((int) $user->role_id, [1, 2])) {
                $collectionRole = 'admin';
            } elseif ($hasCollectionAccess) {
                $profile = \DB::connection('collection_pgsql')
                    ->table('collection_user_profiles')
                    ->where('user_id', $user->id)
                    ->first();
                if ($profile) {
                    $collectionRole = $profile->role;
                }
            }

            return $this->successResponse([
                'success' => true,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'company' => $company,
                'roles' => $roles,
                'modules' => $availableModules,
                'collection_role' => $collectionRole,
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'is_liquidated_today' => ($user->role_id === 5 && $user->seller)
                    ? \App\Models\Liquidation::where('seller_id', $user->seller->id)
                        ->whereDate('date', \Carbon\Carbon::now('America/Lima')->toDateString())
                        ->exists()
                    : false,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al iniciar sesión');
        }
    }

    public function logout()
    {
        try {
            $user = Auth::user();
            $user->token_revoked = 1;
            if ($user instanceof \App\Models\User) {
                $user->save();
            } else {
                throw new \Exception('Invalid user instance');
            }

            $timezone = request()->has('timezone') ? request()->get('timezone') : null;
            $logoutAt = $timezone ? Carbon::now($timezone) : now();
            
            // Note: IP is already stored from login, but logout_at is updated
            SessionLog::where('user_id', $user->id)
                ->whereNull('logout_at')
                ->latest()
                ->first()
                ?->update(['logout_at' => $logoutAt]);

            Auth::logout();

            return $this->successResponse([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al cerrar sesión');
        }
    }

    public function logoutSession($sessionId)
    {
        try {
            $session = SessionLog::findOrFail($sessionId);
            $session->update([
                'logout_at' => Carbon::now('America/Lima')
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Sesión cerrada correctamente por el administrador'
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al cerrar la sesión remota');
        }
    }

    public function changePassword($params)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($params, [
                'current_password' => ['required'],
                'new_password'     => ['required', 'min:8'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $user = Auth::user();

            if (!Hash::check($params['current_password'], $user->password)) {
                return $this->errorResponse('La contraseña actual no es correcta', 401);
            }

            $user->password             = Hash::make($params['new_password']);
            $user->must_change_password = false;  // clear the force-change flag
            $user->save();

            DB::commit();

            return $this->successResponse([
                'success'              => true,
                'message'              => 'Contraseña actualizada correctamente',
                'must_change_password' => false,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->handlerException('Error al cambiar la contraseña');
        }
    }

    public function sendPasswordResetLink($email)
    {
        try {
            $validator = Validator::make(['email' => $email], [
                'email' => ['required', 'email'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $response = Password::sendResetLink(['email' => $email]);

            if ($response === Password::RESET_LINK_SENT) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'El enlace para restablecer la contraseña ha sido enviado a su correo electrónico'
                ]);
            } else {
                return $this->errorResponse('No se pudo enviar el enlace de reseteo', 500);
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al enviar el enlace para restablecer la contraseña');
        }
    }

    public function resetPassword($params)
    {
        try {
            $validator = Validator::make($params, [
                'email' => ['required', 'email'],
                'token' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $response = Password::reset(
                [
                    'email' => $params['email'],
                    'password' => $params['password'],
                    'password_confirmation' => $params['password_confirmation'],
                    'token' => $params['token'],
                ],
                function ($user, $password) {
                    $user->password = Hash::make($password);
                    $user->save();
                }
            );

            if ($response === Password::PASSWORD_RESET) {
                return $this->successResponse([
                    'success' => true,
                    'message' => 'Contraseña restablecida correctamente',
                ]);
            } else {
                return $this->errorResponse('Token inválido o expirado', 401);
            }
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al restablecer la contraseña');
        }
    }
}
