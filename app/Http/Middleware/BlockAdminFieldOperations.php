<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cobrar y colocar es trabajo de CAMPO. El Super-Admin (rol 1) y el Admin de
 * empresa (rol 2) administran, no operan la ruta: no registran pagos, no
 * colocan créditos nuevos y no renuevan.
 *
 * El motivo es de control, no de permisos: un pago cargado desde el
 * back-office entra en la caja del cobrador sin que él lo haya recibido, y
 * queda firmado con el usuario del admin. La liquidación del día le cierra
 * con un recaudo que no hizo. Lo mismo con un crédito colocado a distancia:
 * el desembolso sale de una caja que el admin no tiene en la mano.
 *
 * Aplica a los tres endpoints de alta, y solo a esos:
 *   - PaymentController@create  (POST payment/create)
 *   - CreditController@create   (POST credit/create)
 *   - CreditController@renew    (POST credit/renew)
 *
 * Lo que NO toca, a propósito:
 *   - Consultas, reportes y detalle: el admin sigue viendo todo.
 *   - Correcciones sobre movimientos ya existentes (editar/eliminar/reaplicar):
 *     esas son justamente su tarea de back-office.
 *   - La importación masiva de clientes/créditos (ImportController), que es
 *     una operación administrativa por definición.
 *   - Gastos e ingresos: el admin SÍ los carga (ver EnforcesCashOpen).
 *   - Cobrador (5) y Supervisor (6): son los roles de campo, pasan derecho.
 *
 * Debe registrarse DESPUÉS de auth:api para que $request->user() esté resuelto.
 */
class BlockAdminFieldOperations
{
    /**
     * Roles de back-office. No se leen de la BD a propósito: son los roles
     * fijos del sistema, igual que en EnforcesCashOpen y en los services.
     */
    private const BACK_OFFICE_ROLES = [1, 2];

    /**
     * Código que el frontend usa para distinguir este 403 de los demás y
     * mostrar el mensaje correcto en lugar del error genérico.
     */
    public const ADMIN_FIELD_OPERATION_BLOCKED = 'ADMIN_FIELD_OPERATION_BLOCKED';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ?? Auth::user();

        if (!$user || !in_array((int) ($user->role_id ?? 0), self::BACK_OFFICE_ROLES, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code'    => self::ADMIN_FIELD_OPERATION_BLOCKED,
            'message' => 'Los pagos y la colocación o renovación de créditos son operación de campo: '
                . 'los registra el cobrador de la ruta, o el supervisor sobre la ruta que está atendiendo. '
                . 'Desde una cuenta administrativa solo se consultan.',
        ], 403);
    }
}
