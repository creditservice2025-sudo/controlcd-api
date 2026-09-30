<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta por users.role_id (ej. `role.id:1,2`).
 *
 * Existe porque el middleware `role:` de Spatie consulta model_has_roles y hay
 * usuarios con role_id correcto pero sin rol Spatie asignado: el Admin recibía
 * "User does not have the right roles". Mismo criterio que el Gate::before de
 * AppServiceProvider (role_id manda). No asigna ni modifica roles/permisos.
 */
class EnsureRoleId
{
    public function handle(Request $request, Closure $next, string ...$roleIds): Response
    {
        $user = $request->user();
        $allowed = array_map('intval', $roleIds);

        if (!$user || !in_array((int) $user->role_id, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
