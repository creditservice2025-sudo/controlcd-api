<?php

namespace App\Http\Middleware;

use App\Services\LoginService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Middleware que aplica la regla de exclusividad Cobrador (rol 5) ↔
 * Supervisor (rol 6).
 *
 * Si el usuario autenticado es Cobrador y existe la clave de bloqueo
 * (`supervisor_lock:cobrador:{user_id}`) puesta por LoginService cuando un
 * Supervisor inicia sesión, devuelve 401 con código
 * SESSION_REVOKED_BY_SUPERVISOR para que el frontend muestre el modal
 * "Sesión finalizada por supervisión" en lugar del flujo estándar de token
 * expirado.
 *
 * Aplica tanto en Web como en APK: el bloqueo es total e independiente de
 * la plataforma.
 *
 * Debe registrarse DESPUÉS de auth:api para que $request->user() esté
 * resuelto.
 */
class CheckSupervisorLock
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();

        if ($user && (int) ($user->role_id ?? 0) === 5) {
            $lockKey = "supervisor_lock:cobrador:{$user->id}";
            if (Cache::has($lockKey)) {
                return response()->json([
                    'success' => false,
                    'code' => LoginService::SESSION_REVOKED_BY_SUPERVISOR,
                    'message' => 'Su sesión ha sido cerrada porque su supervisor ha iniciado una revisión de la cartera. Esta acción es parte de los controles operativos de la empresa. Para reanudar sus actividades, comuníquese con su supervisor.',
                ], 401);
            }
        }

        return $next($request);
    }
}
