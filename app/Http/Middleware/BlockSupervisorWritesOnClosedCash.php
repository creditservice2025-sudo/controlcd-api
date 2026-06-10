<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Cuando un Supervisor (rol 6) está supervisando a un vendedor cuya caja
 * del día ya fue cerrada (status pending/auto/approved), este middleware
 * bloquea cualquier operación de mutación (crear/editar/eliminar pagos,
 * gastos, ingresos, créditos, ajustes de caja, etc.) y le devuelve 403
 * con el código SUPERVISED_CASH_CLOSED_READ_ONLY.
 *
 * El supervisor mantiene acceso de SOLO LECTURA: puede ver listados,
 * reportes y el detalle de la liquidación del día. Solo se bloquean los
 * endpoints de mutación, decididos por el controller que aplique este
 * middleware en su constructor (->middleware('block.writes.cash.closed')
 * ->only([...])).
 *
 * Dependencias:
 *   - Debe correr DESPUÉS de auth:api (para $request->user())
 *   - Debe correr DESPUÉS de ResolveActiveSeller (para el atributo
 *     active_seller_id). Si el atributo no está, deja pasar — no aplica.
 *
 * No afecta a otros roles (cobrador, admin, super-admin).
 */
class BlockSupervisorWritesOnClosedCash
{
    /**
     * Estados de liquidación considerados "caja cerrada":
     *   - pending  → el vendedor cerró caja, espera aprobación
     *   - auto     → cron cerró la caja automáticamente
     *   - approved → admin/supervisor ya la aprobó
     * (En curso queda fuera: la caja está abierta y operando.)
     */
    private const CLOSED_STATUSES = ['pending', 'auto', 'approved'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();

        // Solo aplica a supervisores (rol 6). Otros roles pasan directo.
        if (!$user || (int) ($user->role_id ?? 0) !== 6) {
            return $next($request);
        }

        $sellerId = $request->attributes->get('active_seller_id');
        if (!$sellerId) {
            // Sin seller activo no podemos juzgar si su caja está cerrada.
            // Dejamos pasar para no romper endpoints del supervisor sobre
            // sí mismo (ej. su perfil, logout, etc.).
            return $next($request);
        }

        try {
            // Calculamos "hoy" en el timezone del país del vendedor. Si no
            // se puede resolver, usamos America/Lima como fallback (mismo
            // criterio que LoginService línea 183).
            $tz = DB::table('sellers as s')
                ->leftJoin('cities as c', 'c.id', '=', 's.city_id')
                ->leftJoin('countries as co', 'co.id', '=', 'c.country_id')
                ->where('s.id', $sellerId)
                ->value('co.timezone');

            $today = Carbon::now($tz ?: 'America/Lima')->toDateString();

            $liq = DB::table('liquidations')
                ->where('seller_id', $sellerId)
                ->whereDate('date', $today)
                ->select('status')
                ->first();

            if ($liq && in_array($liq->status, self::CLOSED_STATUSES, true)) {
                return response()->json([
                    'success' => false,
                    'code'    => 'SUPERVISED_CASH_CLOSED_READ_ONLY',
                    'message' => 'El vendedor ya cerró la caja del día. Solo puedes consultar información, no realizar movimientos.',
                ], 403);
            }
        } catch (\Throwable $e) {
            // FAIL-OPEN: ante falla de infra dejamos pasar. El daño potencial
            // de un movimiento "colado" es contable y reversible; el daño de
            // bloquear al supervisor por un Redis/DB caído es operativo
            // y bloquea la ruta entera.
            Log::warning('[block.writes.cash.closed] check failed, fail-open', [
                'supervisor_id'    => $user->id,
                'active_seller_id' => $sellerId,
                'error'            => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
