<?php

namespace App\Http\Middleware;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Restringe el ingreso del Cobrador (rol 5) en días que su ruta NO trabaja.
 *
 * Hoy cubre el DOMINGO: si seller_configs.works_sundays = false y hoy es
 * domingo en la zona horaria del vendedor, corta cada request con 401. Así no
 * solo se bloquea el login (LoginService), también se expulsa a quien ya
 * estuviera logueado de un día anterior.
 *
 * Debe registrarse DESPUÉS de auth:api para que $request->user() esté resuelto.
 * Solo afecta al rol 5; los demás roles pasan directo.
 *
 * FAIL-OPEN: si algo falla (config ausente, error de zona, etc.) NO bloquea —
 * se prefiere perder la restricción antes que dejar al cobrador sin operar por
 * una falla puntual. Sin config o con works_sundays activo => opera normal.
 */
class CheckSellerWorkingDay
{
    public const ACCESS_BLOCKED_NON_WORKING_DAY = 'ACCESS_BLOCKED_NON_WORKING_DAY';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();

        // Solo aplica al cobrador (rol 5). Otros roles pasan directo.
        if (!$user || (int) ($user->role_id ?? 0) !== 5) {
            return $next($request);
        }

        try {
            $seller = $user->seller;

            if ($seller) {
                $tz = TimezoneHelper::getSellerTimezone($seller);
                $today = Carbon::now($tz)->toDateString();
                if (\App\Services\BusinessCalendar::isNonWorkingDate($seller, $today)) {
                    return response()->json([
                        'success' => false,
                        'code'    => self::ACCESS_BLOCKED_NON_WORKING_DAY,
                        'message' => 'Hoy tu ruta no opera (día de descanso o feriado). Si necesitas ingresar, contacta al administrador.',
                    ], 401);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[seller.workingday] check failed, fail-open', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
