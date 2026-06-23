<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCollectionCompany;
use Illuminate\Http\Request;

/**
 * Configuración del chat de Telegram para notificaciones del módulo Collection.
 * Cada empresa puede configurar su chat_id; si no lo hace, las notificaciones
 * de Collection solo van por in-app.
 */
class CollectionTelegramConfigController extends Controller
{
    use ApiResponse;
    use ResolvesCollectionCompany;

    /**
     * Devuelve la configuración actual de Telegram para la empresa.
     */
    public function show(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $company = Company::find($companyId);
        return $this->successResponse([
            'company_id' => $companyId,
            'telegram_chat_id' => $company->telegram_chat_id ?? null,
            'configured' => !empty($company->telegram_chat_id),
        ]);
    }

    /**
     * Actualiza el chat_id de Telegram de la empresa. Solo admin (rol 1 o 2).
     */
    public function update(Request $request)
    {
        $companyId = $this->resolveOwnCompanyId($request);
        if (!is_int($companyId)) return $companyId;

        $user = $request->user();
        if (!in_array((int) $user->role_id, [1, 2])) {
            return $this->errorResponse('Solo administradores pueden modificar esta configuración', 403);
        }

        $validated = $request->validate([
            'telegram_chat_id' => 'nullable|string|max:80',
        ]);

        $company = Company::find($companyId);
        $company->telegram_chat_id = $validated['telegram_chat_id'] ?: null;
        $company->save();

        return $this->successResponse([
            'success' => true,
            'message' => 'Configuración de Telegram actualizada',
            'data' => [
                'telegram_chat_id' => $company->telegram_chat_id,
                'configured' => !empty($company->telegram_chat_id),
            ],
        ]);
    }
}
