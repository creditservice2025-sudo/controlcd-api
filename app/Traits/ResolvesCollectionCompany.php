<?php

namespace App\Traits;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resuelve y valida el company_id para los endpoints del módulo
 * Deuda & Abono (Collection).
 *
 * Reglas de negocio:
 *  - Cada usuario (Administrador o Cobrador) SOLO puede operar sobre los
 *    datos de su propia empresa. No existe impersonación entre empresas.
 *  - Si el cliente envía un company_id distinto al de su usuario autenticado,
 *    se responde 403 (intento de cross-tenant).
 *  - La empresa debe tener el módulo Deuda & Abono habilitado
 *    (`is_collection_enabled = true`); en caso contrario se responde 403.
 *  - El company_id resuelto se inyecta en el Request via `merge()` para que
 *    cualquier servicio downstream que lea `$request->company_id` use
 *    siempre el valor seguro y no el del payload original.
 */
trait ResolvesCollectionCompany
{
    /**
     * @return int|JsonResponse companyId entero o JsonResponse con el error.
     */
    protected function resolveOwnCompanyId(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $ownCompanyId = $user->company->id ?? $user->seller->company_id ?? null;
        $isSuperAdmin = (int) $user->role_id === 1;
        $requested = $request->input('company_id');

        if ($ownCompanyId) {
            $ownCompanyId = (int) $ownCompanyId;

            // Usuarios normales: si mandan un company_id distinto al propio → 403.
            if (!$isSuperAdmin && $requested !== null && $requested !== '' && (int) $requested !== $ownCompanyId) {
                return response()->json([
                    'message' => 'No autorizado para operar sobre esta empresa'
                ], 403);
            }

            $companyId = $ownCompanyId;
        } elseif ($isSuperAdmin && $requested) {
            // Super Admin sin empresa propia: usa el company_id del request (impersonación).
            $companyId = (int) $requested;
        } else {
            return response()->json(['message' => 'Usuario sin empresa asociada'], 422);
        }

        // Validar que la empresa exista y tenga el módulo habilitado.
        $company = Company::find($companyId);
        if (!$company) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }
        if (!$company->is_collection_enabled) {
            return response()->json([
                'message' => 'El módulo Deuda & Abono no está habilitado para esta empresa'
            ], 403);
        }

        // Usuarios no-admin necesitan tener acceso explícito al módulo Collection.
        if (!$isSuperAdmin && !in_array((int) $user->role_id, [1, 2]) && !$user->is_collection_user) {
            return response()->json([
                'message' => 'No tiene acceso al módulo Deuda & Abono'
            ], 403);
        }

        // Forzar siempre el company_id seguro en el request para los services downstream.
        $request->merge(['company_id' => $companyId]);

        return $companyId;
    }
}
