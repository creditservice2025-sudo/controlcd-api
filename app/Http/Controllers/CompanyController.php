<?php

namespace App\Http\Controllers;

use App\Http\Requests\Company\CompanyRequest;
use App\Http\Requests\Company\CompanyCodeRequest;
use App\Http\Requests\Company\CompanyRucRequest;
use App\Models\Company;
use App\Models\TelegramAudit;
use App\Services\CompanyService;
use App\Services\TelegramService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    use ApiResponse;

    protected $companyService;

    public function __construct(CompanyService $companyService)
    {
        $this->companyService = $companyService;
     /*    $this->middleware('permission:ver_empresa')->only('index');
        $this->middleware('permission:crear_empresa')->only('create');
        $this->middleware('permission:editar_empresa')->only('update');
        $this->middleware('permission:eliminar_empresa')->only('delete'); */
    }

    public function create(CompanyRequest $request)
    {
        try {
            return $this->companyService->create($request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(CompanyRequest $request, $companyId)
    {
        try {
            return $this->companyService->update($companyId, $request);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function delete($companyId)
    {
        try {
            return $this->companyService->delete($companyId);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

  public function index(Request $request)
{
    try {
        $search = $request->input('search', '');
        $perPage = $request->input('per_page', 10);
        $orderBy = $request->input('orderBy', 'created_at');
        $orderDirection = $request->input('orderDirection', 'desc');

        return $this->companyService->index($search, $perPage, $orderBy, $orderDirection);
    } catch (\Exception $e) {
        return $this->errorResponse($e->getMessage(), 500);
    }
}

    public function show($companyId)
    {
        try {
            $company = Company::with('user')->findOrFail($companyId);
            
            return $this->successResponse([
                'success' => true,
                'message' => 'Empresa obtenida exitosamente',
                'data' => $company
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getCompaniesSelect(Request $request)
    {
        try {
            $search = $request->input('search', '');
            $companyId = $request->input('company_id');
            
            $companies = Company::when($search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                             ->orWhere('code', 'like', "%{$search}%");
            })
            ->when($companyId, function ($query, $companyId) {
                return $query->where('id', $companyId);
            })
            ->select('id', 'name', 'code')
            ->limit(20)
            ->get();

            return $this->successResponse([
                'success' => true,
                'message' => 'Empresas para selección obtenidas exitosamente',
                'data' => $companies
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function validateCompanyCode(CompanyCodeRequest $request)
    {
        return $this->successResponse([
            'success' => true,
            'message' => 'Código de empresa válido'
        ]);
    }

    public function validateCompanyRuc(CompanyRucRequest $request)
    {
        return $this->successResponse([
            'success' => true,
            'message' => 'RUC válido'
        ]);
    }

    /**
     * Devuelve la empresa del usuario autenticado. Útil para que el admin
     * de empresa (rol 2) conozca su company_id sin tener que listarlas.
     * SuperAdmin no tiene "su" empresa (devolvemos null).
     */
    public function getMyCompany()
    {
        try {
            $user = \Illuminate\Support\Facades\Auth::user();
            if (!$user) return $this->errorResponse('No autenticado', 401);

            // SA no tiene empresa propia; el endpoint devuelve null.
            if ((int) $user->role_id === 1) {
                return $this->successResponse([
                    'success' => true,
                    'data' => null,
                ]);
            }

            $company = Company::where('user_id', $user->id)->first();

            return $this->successResponse([
                'success' => true,
                'data' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'telegram_feature_enabled' => (bool) $company->telegram_feature_enabled,
                    'telegram_enabled' => (bool) $company->telegram_enabled,
                    'telegram_chat_id' => $company->telegram_chat_id,
                ] : null,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function resendWelcomeEmail(Request $request, $companyId)
    {
        try {
            return $this->companyService->resendWelcomeEmail($companyId, $request->input('password'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Verifica que el usuario autenticado pueda operar sobre esta empresa.
     * - Rol 1 (SuperAdmin): siempre puede.
     * - Rol 2 (Admin empresa): solo su propia empresa (la que pertenece a $user->id).
     * - Otros roles: nunca.
     */
    private function authorizeCompanyAccess(Company $company): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if ((int) $user->role_id === 1) return true;
        if ((int) $user->role_id === 2) {
            return (int) $company->user_id === (int) $user->id;
        }
        return false;
    }

    /**
     * Registra un cambio en telegram_audits. Captura quién, qué, antes/después.
     * Falla silenciosa: el audit nunca debe romper la operación principal.
     */
    private function logTelegramAudit(Company $company, string $action, array $before, array $after): void
    {
        try {
            $request = request();
            TelegramAudit::create([
                'company_id' => $company->id,
                'user_id' => Auth::id(),
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'ip' => $request?->ip(),
                'user_agent' => substr((string) $request?->header('User-Agent'), 0, 250),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[telegram_audit] error: ' . $e->getMessage());
        }
    }

    /**
     * Snapshot serializable de los campos Telegram de la empresa. Se usa para
     * llenar before/after en el audit. No incluye tokens (sensibles).
     */
    private function telegramSnapshot(Company $company): array
    {
        return [
            'telegram_feature_enabled' => (bool) $company->telegram_feature_enabled,
            'telegram_enabled' => (bool) $company->telegram_enabled,
            'telegram_chat_id_has' => !empty($company->telegram_chat_id),
            'telegram_notify_new_client' => (bool) $company->telegram_notify_new_client,
            'telegram_notify_new_credit' => (bool) $company->telegram_notify_new_credit,
            'telegram_notify_new_expense' => (bool) $company->telegram_notify_new_expense,
            'telegram_notify_deleted_expense' => (bool) $company->telegram_notify_deleted_expense,
            'telegram_notify_deleted_credit' => (bool) $company->telegram_notify_deleted_credit,
            'telegram_quiet_hours_start' => $company->telegram_quiet_hours_start,
            'telegram_quiet_hours_end' => $company->telegram_quiet_hours_end,
        ];
    }

    /**
     * Obtiene la configuración Telegram actual de una empresa.
     */
    public function getTelegramConfig($companyId)
    {
        try {
            $company = Company::findOrFail($companyId);

            if (!$this->authorizeCompanyAccess($company)) {
                return $this->errorResponse('No autorizado', 403);
            }

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'telegram_feature_enabled' => (bool) $company->telegram_feature_enabled,
                    'telegram_enabled' => (bool) $company->telegram_enabled,
                    'telegram_chat_id' => $company->telegram_chat_id,
                    'telegram_notify_new_client' => (bool) $company->telegram_notify_new_client,
                    'telegram_notify_new_credit' => (bool) $company->telegram_notify_new_credit,
                    'telegram_notify_new_expense' => (bool) $company->telegram_notify_new_expense,
                    'telegram_notify_deleted_expense' => (bool) $company->telegram_notify_deleted_expense,
                    'telegram_notify_deleted_credit' => (bool) $company->telegram_notify_deleted_credit,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Habilita/deshabilita el módulo Telegram para una empresa.
     * Solo SuperAdmin (rol 1). El admin de empresa no puede tocar esto.
     */
    public function updateTelegramFeature(Request $request, $companyId)
    {
        try {
            $user = Auth::user();
            if (!$user || (int) $user->role_id !== 1) {
                return $this->errorResponse('Solo el SuperAdmin puede configurar esta función.', 403);
            }

            // SA controla: master switch + qué eventos se notifican + quiet hours.
            $validated = Validator::make($request->all(), [
                'telegram_feature_enabled' => 'required|boolean',
                'telegram_notify_new_client' => 'sometimes|boolean',
                'telegram_notify_new_credit' => 'sometimes|boolean',
                'telegram_notify_new_expense' => 'sometimes|boolean',
                'telegram_notify_deleted_expense' => 'sometimes|boolean',
                'telegram_notify_deleted_credit' => 'sometimes|boolean',
                'telegram_quiet_hours_start' => 'nullable|date_format:H:i',
                'telegram_quiet_hours_end' => 'nullable|date_format:H:i',
            ])->validate();

            $company = Company::findOrFail($companyId);
            $before = $this->telegramSnapshot($company);

            $company->telegram_feature_enabled = (bool) $validated['telegram_feature_enabled'];

            // Si SA desactiva la feature, también desactivamos el envío
            // efectivo. No borramos chat_id ni eventos: si vuelve a habilitar
            // la empresa, el admin recupera su config sin re-vincular.
            if (!$company->telegram_feature_enabled) {
                $company->telegram_enabled = false;
            }

            // Solo asignamos los toggles de eventos enviados (sometimes).
            foreach ([
                'telegram_notify_new_client',
                'telegram_notify_new_credit',
                'telegram_notify_new_expense',
                'telegram_notify_deleted_expense',
                'telegram_notify_deleted_credit',
            ] as $f) {
                if (array_key_exists($f, $validated)) {
                    $company->{$f} = (bool) $validated[$f];
                }
            }

            // Quiet hours: solo se asignan si vienen en el payload (sometimes).
            if (array_key_exists('telegram_quiet_hours_start', $validated)) {
                $company->telegram_quiet_hours_start = $validated['telegram_quiet_hours_start'] ?: null;
            }
            if (array_key_exists('telegram_quiet_hours_end', $validated)) {
                $company->telegram_quiet_hours_end = $validated['telegram_quiet_hours_end'] ?: null;
            }

            $company->save();

            $this->logTelegramAudit(
                $company,
                $company->telegram_feature_enabled ? 'feature_updated_sa' : 'feature_disabled_sa',
                $before,
                $this->telegramSnapshot($company)
            );

            // Invalida cache del listado para que el ícono del frontend
            // refleje el nuevo estado sin esperar el TTL de 60s.
            $this->companyService->invalidateIndexCache();

            return $this->successResponse([
                'success' => true,
                'message' => $company->telegram_feature_enabled
                    ? 'Módulo Telegram habilitado para la empresa.'
                    : 'Módulo Telegram deshabilitado para la empresa.',
                'data' => [
                    'telegram_feature_enabled' => (bool) $company->telegram_feature_enabled,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Actualiza la configuración Telegram de una empresa. No afecta otros
     * campos de la empresa ni la configuración global usada por el OTP.
     */
    public function updateTelegramConfig(Request $request, $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);

            if (!$this->authorizeCompanyAccess($company)) {
                return $this->errorResponse('No autorizado', 403);
            }

            if (!$company->telegram_feature_enabled) {
                return $this->errorResponse(
                    'El módulo Telegram no está habilitado para esta empresa. Contacta al administrador del sistema.',
                    403
                );
            }

            // Empresa admin solo controla: pausa (telegram_enabled) y
            // su chat_id. Los toggles de eventos los gestiona el SA.
            $validated = Validator::make($request->all(), [
                'telegram_enabled' => 'required|boolean',
                'telegram_chat_id' => 'nullable|string|max:50',
            ])->validate();

            // Si habilita pero no envía chat_id, mantenemos el anterior si existe.
            if (!empty($validated['telegram_enabled']) && empty($validated['telegram_chat_id'])) {
                if (empty($company->telegram_chat_id)) {
                    return $this->errorResponse(
                        'No puedes habilitar Telegram sin vincular un Telegram.',
                        422
                    );
                }
            }

            $before = $this->telegramSnapshot($company);
            $company->fill($validated);
            $company->save();

            // Detectar qué cambió y registrar la acción específica.
            $action = $before['telegram_enabled'] !== (bool) $company->telegram_enabled
                ? ($company->telegram_enabled ? 'notifications_resumed' : 'notifications_paused')
                : 'config_updated_admin';

            $this->logTelegramAudit($company, $action, $before, $this->telegramSnapshot($company));

            return $this->successResponse([
                'success' => true,
                'message' => 'Configuración Telegram actualizada correctamente.',
                'data' => [
                    'telegram_enabled' => (bool) $company->telegram_enabled,
                    'telegram_chat_id' => $company->telegram_chat_id,
                    'telegram_notify_new_client' => (bool) $company->telegram_notify_new_client,
                    'telegram_notify_new_credit' => (bool) $company->telegram_notify_new_credit,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Genera un token de vinculación y devuelve la URL deep link para que el
     * admin de la empresa la abra en Telegram. Vincula automáticamente al
     * chat del usuario que toque START.
     */
    public function startTelegramLink($companyId, TelegramService $telegram)
    {
        try {
            $company = Company::findOrFail($companyId);

            if (!$this->authorizeCompanyAccess($company)) {
                return $this->errorResponse('No autorizado', 403);
            }

            if (!$company->telegram_feature_enabled) {
                return $this->errorResponse(
                    'El módulo Telegram no está habilitado para esta empresa.',
                    403
                );
            }

            $data = $telegram->generateLinkToken($company);

            return $this->successResponse([
                'success' => true,
                'message' => 'Token de vinculación generado. Válido por 15 minutos.',
                'data' => $data,
            ]);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Envía un mensaje de prueba al chat configurado de la empresa.
     * Útil para validar el chat_id antes de habilitar las notificaciones.
     */
    /**
     * Historial de notificaciones Telegram de una empresa. SA puede ver
     * cualquiera; empresa admin solo la suya.
     */
    public function getTelegramHistory(Request $request, $companyId)
    {
        try {
            $company = Company::findOrFail($companyId);
            if (!$this->authorizeCompanyAccess($company)) {
                return $this->errorResponse('No autorizado', 403);
            }

            $perPage = min(100, (int) $request->input('per_page', 25));
            $type = $request->input('type');
            $status = $request->input('status');

            $query = \App\Models\TelegramLog::where('company_id', $company->id);
            if ($type) $query->where('type', $type);
            if ($status) $query->where('status', $status);

            $logs = $query->orderByDesc('id')->paginate($perPage);

            return $this->successResponse([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Métricas Telegram para SA: contadores por status y tipo en las
     * últimas 24h, agrupados por empresa si se pasa company_id.
     */
    public function getTelegramMetrics(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || (int) $user->role_id !== 1) {
                return $this->errorResponse('Solo SuperAdmin', 403);
            }

            $hours = max(1, min(720, (int) $request->input('hours', 24))); // 1h - 30d
            $since = now()->subHours($hours);
            $companyId = $request->input('company_id');

            $base = \App\Models\TelegramLog::where('created_at', '>=', $since);
            if ($companyId) $base->where('company_id', $companyId);

            $byStatus = (clone $base)->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')->pluck('count', 'status');

            $byType = (clone $base)->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')->pluck('count', 'type');

            return $this->successResponse([
                'success' => true,
                'data' => [
                    'period_hours' => $hours,
                    'since' => $since->toIso8601String(),
                    'total' => (clone $base)->count(),
                    'by_status' => $byStatus,
                    'by_type' => $byType,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function testTelegram(Request $request, $companyId, TelegramService $telegram)
    {
        try {
            $company = Company::findOrFail($companyId);

            if (!$this->authorizeCompanyAccess($company)) {
                return $this->errorResponse('No autorizado', 403);
            }

            if (!$company->telegram_feature_enabled) {
                return $this->errorResponse(
                    'El módulo Telegram no está habilitado para esta empresa.',
                    403
                );
            }

            // Si el front aún no guardó pero quiere probar con un chat_id ad-hoc.
            if ($request->filled('telegram_chat_id')) {
                $company = clone $company;
                $company->telegram_chat_id = $request->input('telegram_chat_id');
            }

            $result = $telegram->sendTestToCompany($company);

            if ($result['success']) {
                return $this->successResponse([
                    'success' => true,
                    'message' => $result['message'],
                ]);
            }

            return $this->errorResponse($result['message'], 422);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

}