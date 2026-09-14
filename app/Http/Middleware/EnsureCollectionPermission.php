<?php

namespace App\Http\Middleware;

use App\Models\Collection\CollectionUserProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Aplica los permisos granulares del módulo Collection (Deuda & Abono),
 * guardados en collection_user_profiles.permissions (Postgres), a las rutas
 * collection/v1. Antes NO había ningún enforcement: las rutas confiaban solo
 * en checks de rol admin dentro de algunos controllers.
 *
 * Diseño FAIL-SAFE (para no dejar afuera a usuarios legítimos en el primer
 * despliegue de enforcement):
 *  - Super-Admin / Admin (role_id 1 y 2) siempre pasan (acceso total al módulo).
 *  - Sin empresa resoluble, sin perfil de Collection, o error leyendo el
 *    perfil → se PERMITE (y se loguea). Nunca queda peor que el estado previo
 *    (todo abierto).
 *  - Solo se DENIEGA (403) cuando el usuario TIENE perfil y le FALTA el permiso.
 */
class EnsureCollectionPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request); // auth:api ya cubre la autenticación
        }

        // Admin / Super-Admin: acceso total al módulo.
        if (in_array((int) ($user->role_id ?? 0), [1, 2], true)) {
            return $next($request);
        }

        try {
            $companyId = $user->company->id ?? ($user->seller->company_id ?? null);
            if (!$companyId) {
                // El controller (ResolvesCollectionCompany) ya responde 422.
                return $next($request);
            }

            $profile = CollectionUserProfile::where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->first();

            if (!$profile) {
                Log::info('[collection.permission] usuario sin perfil de Collection; se permite (fail-open)', [
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'permission' => $permission,
                ]);
                return $next($request);
            }

            if (!$profile->hasPermission($permission)) {
                return response()->json([
                    'success' => false,
                    'code' => 'COLLECTION_PERMISSION_DENIED',
                    'message' => 'No tenés permiso para esta acción en Deuda & Abono.',
                    'required_permission' => $permission,
                ], 403);
            }
        } catch (\Throwable $e) {
            Log::warning('[collection.permission] error evaluando permiso; se permite (fail-safe): ' . $e->getMessage());
            return $next($request);
        }

        return $next($request);
    }
}
