<?php

namespace App\Http\Middleware;

use App\Services\SupervisorLockService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Para el rol Supervisor (6) que tiene varios cobradores asignados, este
 * middleware resuelve cuál de ellos está "activo" para esta sesión y lo
 * expone como atributo del request para que los services lo consuman.
 *
 * El frontend del APK envía el header `X-Active-Seller-Id` con el seller_id
 * elegido. Validamos que:
 *   1. El user autenticado sea rol 6 (otros roles ignoran el header).
 *   2. El seller_id venga en su lista de user_routes (no puede impersonar
 *      sellers ajenos).
 *
 * Si todo OK, queda accesible en services / controllers vía:
 *     $request->attributes->get('active_seller_id')
 *
 * Si el header no viene o no es válido, el atributo queda en null y el
 * comportamiento legacy del endpoint se mantiene (importante para no
 * romper código actual).
 */
class ResolveActiveSeller
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        // Roles con VENDEDORES ASIGNADOS en `user_routes`: supervisor (6),
        // secretaria y cualquier rol de oficina al que se le asignen rutas.
        //
        // Antes esto cortaba en el rol 6 y el header se ignoraba para el resto,
        // así que un rol de oficina no tenía forma de decir sobre qué vendedor
        // está operando: ninguna pantalla podía resolver su `seller_id`.
        //
        // Super-Admin (1), Admin (2) y Cobrador (5) siguen ignorando el header:
        // su alcance ya se resuelve por empresa o por su propio seller.
        // Solo el Supervisor (regla de siempre) y los roles parametrizables
        // (Secretaria y roles nuevos). Todos los demás ignoran el header, igual
        // que antes.
        $rolUsuario = (int) ($user->role_id ?? 0);
        if (!($rolUsuario === 6 || \App\Support\Roles::esParametrizable($rolUsuario))) {
            return $next($request);
        }

        $sellerId = $request->header('X-Active-Seller-Id');
        if (!$sellerId || !is_numeric($sellerId)) return $next($request);

        $sellerId = (int) $sellerId;

        $allowed = DB::table('user_routes')
            ->where('user_id', $user->id)
            ->where('seller_id', $sellerId)
            ->exists();

        if (!$allowed) {
            return response()->json([
                'success' => false,
                // El supervisor conserva su mensaje de siempre.
                'message' => (int) $user->role_id === 6
                    ? 'La ruta seleccionada no está asignada a este supervisor.'
                    : 'La ruta seleccionada no está asignada a este usuario.',
            ], 403);
        }

        $request->attributes->set('active_seller_id', $sellerId);

        // Exclusividad por RUTA ACTIVA: bloquear SOLO al cobrador de la ruta
        // que el supervisor está viendo (y liberar la anterior si cambió).
        // Antes esto se hacía a TODOS los cobradores en el login; ahora sigue
        // a la ruta activa, así los demás vendedores del supervisor siguen
        // operando. FAIL-OPEN: el lock es operativo, no crítico — si falla, no
        // bloqueamos (peor caso: el cobrador supervisado opera unos segundos).
        //
        // EL LOCK ES EXCLUSIVO DEL SUPERVISOR (rol 6). Bloquea al cobrador
        // mientras su ruta está siendo supervisada, y eso tiene sentido entre
        // supervisor y cobrador: no pueden operar la misma caja a la vez. Una
        // secretaria que consulta la misma ruta desde la oficina NO debe dejar
        // al cobrador sin poder trabajar, así que no lo dispara.
        if ((int) $user->role_id === 6) {
            try {
                app(SupervisorLockService::class)->syncActiveRoute((int) $user->id, $sellerId);
            } catch (\Throwable $e) {
                Log::warning('[supervisor.lock] no se pudo sincronizar lock de ruta activa', [
                    'supervisor_id' => $user->id,
                    'seller_id'     => $sellerId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
