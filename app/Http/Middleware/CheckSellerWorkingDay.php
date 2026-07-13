<?php

namespace App\Http\Middleware;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Restringe la operación en días que la ruta NO trabaja, para quienes operan
 * sobre una ruta: el Cobrador (rol 5) sobre la suya y el Supervisor (rol 6)
 * sobre la ruta que tiene activa. El supervisor puede hacer las MISMAS
 * operaciones que el cobrador, así que si la ruta descansa hoy, tampoco él
 * debe poder ingresar a ella.
 *
 * Hoy cubre el DOMINGO: si seller_configs.works_sundays = false y hoy es
 * domingo en la zona horaria del vendedor, corta cada request. Así no solo se
 * bloquea el login (LoginService), también se expulsa a quien ya estuviera
 * logueado de un día anterior. (También cubre feriados vía BusinessCalendar.)
 *
 * Diferencia de respuesta por rol:
 *   - Cobrador (5): 401. Solo tiene esa ruta; se le cierra la sesión.
 *   - Supervisor (6): 403 con código ROUTE_BLOCKED_NON_WORKING_DAY. NO se le
 *     desloguea (supervisa otras rutas que sí pueden operar hoy); el frontend
 *     lo devuelve al selector de rutas. Solo se bloquea la ruta activa.
 *
 * Debe registrarse DESPUÉS de auth:api (para $request->user()) y DESPUÉS de
 * active.seller (para el atributo active_seller_id del supervisor). Los demás
 * roles pasan directo.
 *
 * FAIL-OPEN: si algo falla (config ausente, error de zona, etc.) NO bloquea —
 * se prefiere perder la restricción antes que dejar sin operar por una falla
 * puntual. Sin config o con works_sundays activo => opera normal.
 */
class CheckSellerWorkingDay
{
    public const ACCESS_BLOCKED_NON_WORKING_DAY = 'ACCESS_BLOCKED_NON_WORKING_DAY';
    public const ROUTE_BLOCKED_NON_WORKING_DAY  = 'ROUTE_BLOCKED_NON_WORKING_DAY';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();
        $roleId = (int) ($user->role_id ?? 0);

        // Solo aplica a quienes operan sobre una ruta: cobrador (5) y
        // supervisor (6). Otros roles pasan directo.
        if (!$user || !in_array($roleId, [5, 6], true)) {
            return $next($request);
        }

        try {
            // Cobrador: su propia ruta. Supervisor: la ruta que tiene activa
            // (resuelta y validada por el middleware active.seller). Si el
            // supervisor no tiene ruta activa, no hay ruta que juzgar → pasa.
            if ($roleId === 5) {
                $seller = $user->seller;
            } else {
                $activeSellerId = $request->attributes->get('active_seller_id');
                $seller = $activeSellerId
                    ? \App\Models\Seller::find($activeSellerId)
                    : null;
            }

            if ($seller) {
                $tz = TimezoneHelper::getSellerTimezone($seller);
                $today = Carbon::now($tz)->toDateString();
                if (\App\Services\BusinessCalendar::isNonWorkingDate($seller, $today)) {
                    // Cobrador: 401 (cierra su única sesión). Supervisor: 403
                    // (solo bloquea la ruta activa, no lo desloguea).
                    return $roleId === 5
                        ? response()->json([
                            'success' => false,
                            'code'    => self::ACCESS_BLOCKED_NON_WORKING_DAY,
                            'message' => 'Hoy tu ruta no opera (día de descanso o feriado). Si necesitas ingresar, contacta al administrador.',
                        ], 401)
                        : response()->json([
                            'success' => false,
                            'code'    => self::ROUTE_BLOCKED_NON_WORKING_DAY,
                            'message' => 'Esta ruta no opera hoy (día de descanso o feriado). Selecciona otra ruta o contacta al administrador.',
                        ], 403);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[seller.workingday] check failed, fail-open', [
                'user_id' => $user->id,
                'role_id' => $roleId,
                'error'   => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
