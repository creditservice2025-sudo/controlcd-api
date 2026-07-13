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
use App\Mail\ResetPassword;
use App\Models\Liquidation;
use Hash;
use Carbon\Carbon;

class LoginService
{

    use ApiResponse;

    /**
     * Roles permitidos para entrar al APK móvil. Cualquier otro rol queda
     * limitado al portal web. Frontend Capacitor envía el header
     * X-Client-Type: mobile en cada request; ausencia del header se asume
     * como cliente web.
     */
    private const MOBILE_ALLOWED_ROLES = [5, 6]; // 5=Cobrador, 6=Supervisor

    /**
     * Código que el frontend usa para distinguir un 401 normal de uno
     * provocado por el supervisor revocando la sesión. Usado en el
     * interceptor de axios para mostrar el modal "Sesión finalizada por
     * supervisión" en lugar del flujo estándar de login expirado.
     */
    public const SESSION_REVOKED_BY_SUPERVISOR = 'SESSION_REVOKED_BY_SUPERVISOR';

    /**
     * Código que el frontend usa cuando se invalida la sesión del cobrador
     * porque cerró su liquidación en algún dispositivo. Su efecto: cualquier
     * teléfono donde el mismo usuario siga logueado recibirá un 401 con este
     * código y mostrará el modal "Liquidación cerrada — vuelve a iniciar
     * sesión". Es independiente del supervisor.lock.
     */
    public const SESSION_REVOKED_BY_LIQUIDATION_CLOSE = 'SESSION_REVOKED_BY_LIQUIDATION_CLOSE';

    /**
     * Clave de cache que marca al cobrador como bloqueado por haber cerrado
     * su liquidación. Mientras la clave exista (TTL 24h o hasta su próximo
     * login exitoso), el middleware CheckLiquidationClosed devuelve 401.
     */
    public static function liquidationClosedKey(int $cobradorUserId): string
    {
        return "liquidation_closed:cobrador:{$cobradorUserId}";
    }

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
                ->with('city', 'seller')
                ->first();

                \Log::info('Usuario encontrado: ' . ($user ? $user->email : 'Ninguno'));

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                return $this->errorResponse(['Los datos introducidos son inválidos, verifica e intenta nuevamente'], 401);
            }

            // ============================================================
            // RESTRICCIÓN DE PLATAFORMA (APK vs Web)
            // El frontend Capacitor envía X-Client-Type: mobile en cada
            // request; el web NO lo envía. Solo Cobrador (5) y Supervisor (6)
            // pueden entrar al APK.
            // ============================================================
            $clientType = request()->header('X-Client-Type', 'web');
            if ($clientType === 'mobile' && !in_array((int) $user->role_id, self::MOBILE_ALLOWED_ROLES, true)) {
                return $this->errorResponse([
                    'Esta aplicación móvil está disponible únicamente para Cobradores y Supervisores de campo. Para acceder a sus funciones administrativas, ingrese al portal web con sus credenciales habituales.'
                ], 403);
            }

            // El Cobrador/Vendedor (rol 5) SOLO puede operar desde el APK móvil,
            // nunca desde el portal web (el web no envía X-Client-Type: mobile).
            if ($clientType !== 'mobile' && (int) $user->role_id === 5) {
                return $this->errorResponse([
                    'El acceso de Cobrador está disponible únicamente desde la aplicación móvil. Por favor, ingrese desde su dispositivo móvil.'
                ], 403);
            }

            // ============================================================
            // EXCLUSIVIDAD COBRADOR (rol 5) ↔ SUPERVISOR (rol 6)
            // Si el cobrador intenta entrar mientras su Supervisor tiene
            // sesión activa, se bloquea independiente de la plataforma.
            // FAIL-OPEN: si la cache no responde, permitimos el login
            // (más vale que entre uno sin validar que tener a todos los
            // cobradores fuera del sistema por falla de cache).
            // ============================================================
            if ((int) $user->role_id === 5) {
                try {
                    if (Cache::has(\App\Services\SupervisorLockService::cobradorLockKey((int) $user->id))) {
                        return $this->errorResponse([
                            'Su supervisor se encuentra realizando una revisión operativa de la cartera asignada. Por motivos de control y seguridad, su sesión permanecerá inhabilitada mientras dure este proceso. Para continuar con su operación, comuníquese con su supervisor inmediato o reintente el ingreso más tarde.'
                        ], 403);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('[supervisor.lock] cache check fail-open en login cobrador', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // NOTA: el Supervisor (rol 6) YA NO bloquea a sus cobradores al
            // iniciar sesión. El bloqueo pasó a ser por RUTA ACTIVA: se aplica
            // solo al cobrador de la ruta que el supervisor está viendo, y se
            // sincroniza en el middleware ResolveActiveSeller vía
            // SupervisorLockService::syncActiveRoute. Así los demás cobradores
            // vinculados al supervisor pueden seguir operando normalmente.

            $seller = $user->seller;
            // Día de negocio anclado a la zona del VENDEDOR. Antes el bloqueo
            // usaba America/Lima hardcodeado mientras la auto-apertura usaba la
            // zona del request/UTC: cerca de medianoche eran días distintos, y
            // el día real del vendedor podía no abrirse nunca (día huérfano).
            $sellerTz = $seller
                ? \App\Helpers\TimezoneHelper::getSellerTimezone($seller)
                : config('app.timezone');
            $businessToday = \Carbon\Carbon::now($sellerTz)->toDateString();

            if ($seller && $user->role_id === 5) {
                // Bloqueo estricto: Si existe CUALQUIER liquidación para hoy, el vendedor no entra.
                $liquidation = Liquidation::where('seller_id', $seller->id)
                    ->whereDate('date', $businessToday)
                    ->first();

                if ($liquidation && in_array($liquidation->status, ['approved', 'auto', 'pending'])) {
                    return $this->errorResponse(['Ya cerro la liquidación del día. Si desea reabrir la caja debe contactar al administrador'], 401);
                }

                // Bloqueo por día no laborable de la ruta (descanso semanal /
                // feriado), según BusinessCalendar. Anclado al mismo día de
                // negocio del vendedor ($businessToday). El middleware
                // seller.workingday cubre las sesiones ya abiertas.
                if (\App\Services\BusinessCalendar::isNonWorkingDate($seller, $businessToday)) {
                    return $this->errorResponse(['Hoy tu ruta no opera (día de descanso o feriado). Si necesitas ingresar, contacta al administrador.'], 401);
                }
            }

            $token = $user->createToken('USER_AUTH_TOKEN')->accessToken;

            if ($user->token_revoked) {
                $user->update([
                    "token_revoked" => 0
                ]);
            }

            // Login exitoso del cobrador → limpiar cualquier lock residual de
            // cierre de liquidación. Si quedó algo de un día anterior (TTL
            // 24h), este forget asegura que no aplique al nuevo día. Idempotente.
            if ((int) $user->role_id === 5) {
                try {
                    Cache::forget(self::liquidationClosedKey((int) $user->id));
                } catch (\Throwable $e) {
                    \Log::warning('[liquidation.closed] no se pudo limpiar lock en login', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
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

            // Auto-Apertura si es vendedor. Usa el MISMO día de negocio del
            // vendedor que el bloqueo de arriba (no la zona del request/UTC),
            // para que el día que se bloquea y el que se abre coincidan.
            if ($seller && ($user->role_id === 5 || $user->role_id === 3)) {
                try {
                    $liquidationService = app(\App\Services\LiquidationService::class);
                    $liquidationService->getOrCreateLiquidation($seller->id, $businessToday, $sellerTz);
                } catch (\Throwable $e) {
                    // No romper el login, pero NO tragarlo en silencio: log con
                    // contexto suficiente para diagnosticar días sin liquidación.
                    \Log::error('[liquidation.auto-apertura] fallo al abrir el día del vendedor', [
                        'seller_id'     => $seller->id,
                        'business_date' => $businessToday,
                        'timezone'      => $sellerTz,
                        'error'         => $e->getMessage(),
                        'at'            => basename($e->getFile()) . ':' . $e->getLine(),
                    ]);
                }
            }

            return $this->successResponse([
                'success' => true,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'permissions' => $user->getAllPermissions()->pluck('name'),
                // 'is_liquidated_today' = la caja de HOY del vendedor está
                // CERRADA. Usa la MISMA zona del vendedor ($businessToday) y los
                // MISMOS estados cerrados que el bloqueo de más arriba. Antes usaba
                // America/Lima hardcodeado + ->exists() (cualquier estado), que daba
                // true con la caja apenas ABIERTA ('En curso') y trababa al vendedor
                // sin motivo, con desfase de ~1h cerca de medianoche fuera de Perú.
                'is_liquidated_today' => ($user->role_id === 5 && $seller)
                    ? \App\Models\Liquidation::where('seller_id', $seller->id)
                        ->whereDate('date', $businessToday)
                        ->whereIn('status', ['pending', 'auto', 'approved'])
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
            if (!($user instanceof \App\Models\User)) {
                throw new \Exception('Invalid user instance');
            }

            // PRIMERO liberar el bloqueo supervisor→cobrador, ANTES de
            // cualquier otra operación que pueda fallar (ej: el save de
            // token_revoked). Así el cobrador SIEMPRE queda libre aunque algo
            // más en el logout falle. FAIL-OPEN: si la cache no responde,
            // logout sigue su flujo (en el peor caso el lock expira a los 90 min).
            if ((int) $user->role_id === 6) {
                try {
                    // Libera al cobrador de la ruta activa (y cualquier lock
                    // residual). SupervisorLockService es la fuente única de la
                    // lógica y las claves; el fallback interno cubre el caso de
                    // cache expirada liberando todos los cobradores asignados.
                    app(\App\Services\SupervisorLockService::class)->releaseAll((int) $user->id);
                    \Log::info('Supervisor cerró sesión y liberó su bloqueo de ruta', [
                        'supervisor_id' => $user->id,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('[supervisor.lock] no se pudo liberar lock en logout', [
                        'supervisor_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Marcar token revocado (best-effort). Va DESPUÉS de liberar el
            // lock para que, si algo falla acá, el cobrador ya quedó libre.
            try {
                $user->token_revoked = 1;
                $user->save();
            } catch (\Throwable $e) {
                \Log::warning('[logout] no se pudo marcar token_revoked', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            $timezone = request()->has('timezone') ? request()->get('timezone') : null;
            $logoutAt = $timezone ? Carbon::now($timezone) : now();

            // Note: IP is already stored from login, but logout_at is updated
            SessionLog::where('user_id', $user->id)
                ->whereNull('logout_at')
                ->latest()
                ->first()
                ?->update(['logout_at' => $logoutAt]);

            // Best-effort: en el guard `api` (Passport TokenGuard/RequestGuard)
            // Auth::logout() no tiene un logout() real y puede lanzar. El cierre
            // efectivo en Passport lo hace el frontend al descartar el token;
            // acá no debe romper la respuesta (el lock ya fue liberado arriba).
            try {
                Auth::logout();
            } catch (\Throwable $e) {
                \Log::warning('[logout] Auth::logout no soportado en guard api', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }

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
