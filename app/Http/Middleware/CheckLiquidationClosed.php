<?php

namespace App\Http\Middleware;

use App\Services\LoginService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Middleware paralelo a CheckSupervisorLock. Cuando un Cobrador (rol 5)
 * cierra su liquidación del día desde cualquiera de sus dispositivos,
 * LiquidationController::storeLiquidation marca la cache key
 * `liquidation_closed:cobrador:{user_id}` con TTL de 24h.
 *
 * Este middleware corre en cada request del cobrador y, si la key existe,
 * devuelve 401 con el código SESSION_REVOKED_BY_LIQUIDATION_CLOSE para que
 * el frontend muestre el modal "Liquidación cerrada — vuelve a iniciar
 * sesión" en todos los demás dispositivos donde el mismo usuario seguía
 * logueado.
 *
 * Debe registrarse DESPUÉS de auth:api para que $request->user() esté
 * resuelto. Es independiente del supervisor.lock — usa otra cache key y
 * otro código de respuesta. No afecta a otros roles (admin, supervisor).
 */
class CheckLiquidationClosed
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();

        // Solo aplica al cobrador (rol 5). Otros roles pasan directo.
        if (!$user || (int) ($user->role_id ?? 0) !== 5) {
            return $next($request);
        }

        // FAIL-OPEN: igual criterio que CheckSupervisorLock. Si la cache no
        // responde (Redis caído, etc.) NO bloqueamos al cobrador — preferimos
        // perder la funcionalidad antes que dejarlo sin operar por una falla
        // de infraestructura.
        try {
            $lockKey = LoginService::liquidationClosedKey((int) $user->id);
            if (Cache::has($lockKey)) {
                return response()->json([
                    'success' => false,
                    'code'    => LoginService::SESSION_REVOKED_BY_LIQUIDATION_CLOSE,
                    'message' => 'Tu liquidación del día ya fue cerrada. Vuelve a iniciar sesión para continuar.',
                ], 401);
            }
        } catch (\Throwable $e) {
            Log::warning('[liquidation.closed] cache check failed, fail-open', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
