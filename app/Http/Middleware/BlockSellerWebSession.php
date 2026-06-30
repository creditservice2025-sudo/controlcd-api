<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * El Cobrador/Vendedor (rol 5) SOLO puede operar desde el APK móvil. El APK
 * (Capacitor) envía `X-Client-Type: mobile` en cada request; el portal web no.
 *
 * Este middleware corre en cada request autenticado: si un rol 5 llega SIN el
 * header mobile (= web), devuelve 401 para forzar el cierre de la sesión en el
 * navegador (el interceptor del frontend limpia el token y redirige al login).
 * Así se cortan también las sesiones web YA abiertas, no solo el login nuevo.
 *
 * Debe registrarse DESPUÉS de auth:api para que $request->user() esté resuelto.
 * No afecta a otros roles (admin, supervisor) ni al APK.
 */
class BlockSellerWebSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();

        // Solo aplica al cobrador/vendedor (rol 5). Otros roles pasan directo.
        if (!$user || (int) ($user->role_id ?? 0) !== 5) {
            return $next($request);
        }

        $clientType = $request->header('X-Client-Type', 'web');
        if ($clientType !== 'mobile') {
            return response()->json([
                'success' => false,
                'code'    => 'SESSION_BLOCKED_SELLER_WEB',
                'message' => 'El acceso de Cobrador está disponible únicamente desde la aplicación móvil. Tu sesión web ha sido cerrada.',
            ], 401);
        }

        return $next($request);
    }
}
