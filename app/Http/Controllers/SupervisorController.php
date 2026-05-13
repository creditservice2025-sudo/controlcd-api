<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
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
                'co.name as country_name',
            )
            ->orderBy('co.name')
            ->orderBy('c.name')
            ->orderBy('u.name')
            ->get();

        return $this->successResponse([
            'success' => true,
            'data' => $rows,
        ]);
    }
}
