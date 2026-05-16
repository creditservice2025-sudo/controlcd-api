<?php

namespace App\Http\Middleware;

use App\Services\LoginService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // Solo aplica al cobrador (rol 5). Otros roles pasan directo.
        if (!$user || (int) ($user->role_id ?? 0) !== 5) {
            return $next($request);
        }

        // FAIL-OPEN: el lock es una regla operativa (no operar mientras
        // supervisa el rol 6), NO un control de acceso crítico. Si la
        // cache falla (Redis caído, driver `database` con MySQL caído,
        // etc.) NO bloqueamos al cobrador — preferimos perder la
        // funcionalidad de revisión esos segundos antes que dejar a 140+
        // cobradores sin poder operar por una falla de infraestructura.
        try {
            $lockKey = "supervisor_lock:cobrador:{$user->id}";
            if (Cache::has($lockKey)) {
                return response()->json([
                    'success' => false,
                    'code' => LoginService::SESSION_REVOKED_BY_SUPERVISOR,
                    'message' => 'Su sesión ha sido cerrada porque su supervisor ha iniciado una revisión de la cartera. Esta acción es parte de los controles operativos de la empresa. Para reanudar sus actividades, comuníquese con su supervisor.',
                ], 401);
            }
        } catch (\Throwable $e) {
            Log::warning('[supervisor.lock] cache check failed, fail-open', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
