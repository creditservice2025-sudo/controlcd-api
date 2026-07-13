<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints específicos del flujo Supervisor (rol 6) en el APK.
 *
 * El supervisor entra al APK y necesita elegir una ruta a supervisar entre
 * sus cobradores asignados (tabla user_routes). Una vez elegida, el resto
 * de la app se comporta como si fuera ese cobrador (filtrado vía middleware
 * ResolveActiveSeller leyendo el header X-Active-Seller-Id).
 */
class SupervisorController extends Controller
{
    use ApiResponse;

    /**
     * Lista los sellers (cobradores) asignados al supervisor logueado para
     * que pueda elegir cuál supervisar. Devuelve datos suficientes para
     * mostrar nombre + ciudad/ruta en el modal de selección del APK.
     */
    public function activeSellers()
    {
        $user = Auth::user();
        if (!$user || (int) $user->role_id !== 6) {
            return $this->errorResponse('Solo disponible para Supervisores.', 403);
        }

        // Resolvemos los seller_ids vinculados al supervisor y traemos
        // metadata útil para la UI: nombre del cobrador (vía sellers.user_id
        // → users.name) y ciudad/país de la ruta.
        //
        // user_routes NO tiene unique constraint en (user_id, seller_id)
        // por diseño histórico → puede tener filas repetidas para el
        // mismo seller. Usamos distinct seller_ids primero para evitar
        // mostrar la misma ruta varias veces en el modal de selección.
        $sellerIds = DB::table('user_routes')
            ->where('user_id', $user->id)
            ->pluck('seller_id')
            ->unique()
            ->values()
            ->all();

        if (empty($sellerIds)) {
            return $this->successResponse([
                'success' => true,
                'data' => [],
            ]);
        }

        $rows = DB::table('sellers as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('cities as c', 'c.id', '=', 's.city_id')
            ->leftJoin('countries as co', 'co.id', '=', 'c.country_id')
            ->whereIn('s.id', $sellerIds)
            ->whereNull('s.deleted_at')
            ->select(
                's.id as seller_id',
                'u.name as cobrador_name',
                'u.dni as cobrador_dni',
                'c.id as city_id',
                'c.name as city_name',
                'co.id as country_id',
                'co.name as country_name',
                // timezone del país: lo usaremos para que el supervisor
                // vea fechas/horas en el huso horario del cobrador, no del
                // suyo (evita confusiones cuando supervisa rutas en países
                // distintos al suyo).
                'co.timezone as country_timezone',
            )
            ->orderBy('co.name')
            ->orderBy('c.name')
            ->orderBy('u.name')
            ->get();

        // Modelos Seller (con config + ciudad/país) para consultar el
        // calendario de negocio: BusinessCalendar es la fuente ÚNICA de verdad
        // sobre si una ruta opera hoy (descanso semanal / feriado). Se cargan
        // una sola vez, keyed por id, para no repetir queries en el map.
        $sellersById = \App\Models\Seller::with(['config', 'city.country'])
            ->whereIn('id', $sellerIds)
            ->get()
            ->keyBy('id');

        // Para que el APK pueda mostrar un badge "Caja cerrada" en el modal
        // de selección de ruta (y mostrar el alerta correspondiente al
        // elegirla), añadimos por cada cobrador el estado de su liquidación
        // del día calculada en el timezone del país del vendedor.
        //
        // Estados considerados "caja cerrada": pending, auto, approved.
        // (En curso = caja abierta, operando normalmente.)
        $rows = $rows->map(function ($row) use ($sellersById) {
            $tz = $row->country_timezone ?: 'America/Lima';
            $today = Carbon::now($tz)->toDateString();

            $liq = DB::table('liquidations')
                ->where('seller_id', $row->seller_id)
                ->whereDate('date', $today)
                ->select('status')
                ->first();

            $row->liquidation_today_status = $liq->status ?? null;
            $row->liquidation_today_closed = $liq
                ? in_array($liq->status, ['pending', 'auto', 'approved'], true)
                : false;

            // ¿La ruta descansa hoy (domingo sin works_sundays o feriado)? El
            // APK usa este flag para deshabilitar la ruta en el selector; si
            // aun así intenta entrar, seller.workingday la bloquea con 403.
            // FAIL-OPEN: ante cualquier falla asumimos que opera (no la ocultamos).
            $seller = $sellersById->get($row->seller_id);
            try {
                $row->non_working_today = $seller
                    ? \App\Services\BusinessCalendar::isNonWorkingDate($seller, $today)
                    : false;
            } catch (\Throwable $e) {
                $row->non_working_today = false;
            }

            return $row;
        });

        return $this->successResponse([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Historial de supervisión de una ruta (seller): tramos de quién la
     * supervisó y desde/hasta cuándo. Alimenta el detalle al expandir la fila
     * en "Rutas Activas". Ordenado del más reciente al más antiguo.
     *
     * El tramo en curso viene con ended_at = null (in_progress = true).
     */
    public function supervisionLogs($sellerId)
    {
        $user = Auth::user();
        // Solo roles administrativos consultan el historial (no cobrador/supervisor).
        if (!$user || !in_array((int) $user->role_id, [1, 2, 3, 4], true)) {
            return $this->errorResponse('No autorizado para ver el historial de supervisión.', 403);
        }

        $limit = (int) request()->query('limit', 50);
        $limit = max(1, min($limit, 200));

        // Zona de la ruta: los timestamps están guardados en hora local del
        // vendedor. "Ahora" para tramos en curso se calcula en esa misma zona,
        // y la duración se mide sobre el reloj de pared (naive) para no mezclar
        // husos. Fallback a la zona del servidor si no se resuelve la ruta.
        $seller = \App\Models\Seller::find((int) $sellerId);
        $tz = $seller
            ? \App\Helpers\TimezoneHelper::getSellerTimezone($seller)
            : config('app.timezone');

        $logs = \App\Models\SupervisionLog::with('supervisor:id,name')
            ->where('seller_id', (int) $sellerId)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(function ($log) use ($tz) {
                $startStr = optional($log->started_at)->format('Y-m-d H:i:s');
                $endStr = $log->ended_at
                    ? $log->ended_at->format('Y-m-d H:i:s')
                    : null;

                // Reloj de pared para la duración: fin real o "ahora" en la zona
                // de la ruta si el tramo sigue abierto.
                $endForDuration = $endStr ?? now($tz)->format('Y-m-d H:i:s');
                $duration = $startStr
                    ? \Carbon\Carbon::parse($startStr)->diffInMinutes(\Carbon\Carbon::parse($endForDuration))
                    : null;

                return [
                    'id'               => $log->id,
                    'supervisor_id'    => $log->supervisor_user_id,
                    'supervisor_name'  => optional($log->supervisor)->name,
                    'started_at'       => $startStr,
                    'ended_at'         => $endStr,
                    'end_reason'       => $log->end_reason,
                    'in_progress'      => $endStr === null,
                    'duration_minutes' => $duration,
                ];
            });

        return $this->successResponse([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
