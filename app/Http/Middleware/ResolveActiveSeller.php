<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        if ((int) ($user->role_id ?? 0) !== 6) return $next($request);

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
                'message' => 'La ruta seleccionada no está asignada a este supervisor.',
            ], 403);
        }

        $request->attributes->set('active_seller_id', $sellerId);
        return $next($request);
    }
}
