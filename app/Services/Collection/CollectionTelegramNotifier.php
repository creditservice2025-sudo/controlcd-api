<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCompanyConfig;
use App\Models\Collection\CollectionTelegramLink;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\TelegramLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper sobre la API de Telegram para uso exclusivo del módulo Collection.
 *
 * Deuda & Abono manda por SU PROPIO bot, separado del de Control CD. El orden
 * de resolución del token es:
 *   1. `telegram_collection.report_bot_token` — bot dedicado a los reportes
 *      ("Reporte de Cobranza Diario"), si se creó uno aparte.
 *   2. `telegram_collection.bot_token` — el bot de Deuda & Abono.
 * Nunca cae al bot de Control CD: antes usaba `services.telegram.bot_token` y
 * por eso los mensajes de Collection aparecían mezclados en el mismo chat que
 * las notificaciones del core.
 *
 * El chat destino se resuelve igual, priorizando lo que sea de Collection:
 *   1. `collection_company_configs.settings.telegram_chat_id` (propio del módulo)
 *   2. `companies.telegram_chat_id` (compartido con el core; solo compatibilidad)
 */
class CollectionTelegramNotifier
{
    protected ?string $token;

    public function __construct()
    {
        $this->token = config('services.telegram_collection.report_bot_token')
            ?: config('services.telegram_collection.bot_token');
    }

    /**
     * Envía un mensaje al chat configurado para la empresa $companyId.
     * Devuelve true si se envió, false si no había chat o falló.
     */
    public function sendToCompany(int $companyId, string $message, string $type = 'collection_notification'): bool
    {
        if (!$this->token) {
            Log::warning('CollectionTelegramNotifier: falta TELEGRAM_COLLECTION_BOT_TOKEN (o el de reportes).');
            return false;
        }

        $chatId = $this->resolveChatId($companyId);
        if (!$chatId) {
            // Silencioso: la empresa no configuró Telegram. Solo in-app.
            return false;
        }

        try {
            // Timeout obligatorio: esta llamada corre DENTRO del request del
            // usuario. Sin él, una red lenta (VPN, corte de salida) deja el
            // pedido colgado hasta que PHP lo mata a los 30 s, y el usuario ve
            // un error genérico en una operación que ya se completó en base.
            // El aviso por Telegram es secundario; el catch de abajo lo trata
            // como no entregado y la operación sigue su curso.
            $response = Http::withoutVerifying()
                ->connectTimeout(3)
                ->timeout(5)
                ->post(
                "https://api.telegram.org/bot{$this->token}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ]
            );

            if (!$response->successful()) {
                Log::error('CollectionTelegramNotifier API error: ' . $response->body());
            }

            try {
                TelegramLog::create([
                    'chat_id' => $chatId,
                    'message' => $message,
                    'type' => $type,
                    'status' => $response->successful() ? 'success' : 'failed',
                ]);
            } catch (\Exception $e) {
                Log::error('Error guardando TelegramLog desde Collection: ' . $e->getMessage());
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('CollectionTelegramNotifier exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Chat destino de la empresa. Prioriza el configurado DENTRO de Collection
     * para que el módulo pueda tener su propio grupo, y solo cae al del core
     * (companies.telegram_chat_id) mientras no se configure el propio.
     */
    private function resolveChatId(int $companyId): ?string
    {
        $config = CollectionCompanyConfig::where('company_id', $companyId)->first();
        $settings = is_array($config?->settings) ? $config->settings : [];
        $own = trim((string) ($settings['telegram_chat_id'] ?? ''));

        if ($own !== '') {
            return $own;
        }

        // 2. Telegram del ADMINISTRADOR de la empresa, ya vinculado al bot de
        // Deuda & Abono. Evita cargar el chat a mano y, sobre todo, evita el
        // respaldo del core: `companies.telegram_chat_id` no es unico, hoy hay
        // dos empresas apuntando al mismo chat y sus avisos se mezclan.
        //
        // Se exige rol ADMINISTRATIVO a proposito. Por aca salen los codigos de
        // autorizacion para borrar movimientos, cuyo sentido es que el cobrador
        // NO pueda autorizarse solo: si el destino fuera cualquier vinculo, el
        // mismo cobrador que pide la baja recibiria el codigo.
        $adminChat = $this->resolveAdminLinkChatId($companyId);
        if ($adminChat !== null) {
            return $adminChat;
        }

        $shared = Company::find($companyId)?->telegram_chat_id;
        if (!empty($shared)) {
            Log::info(
                "Collection empresa {$companyId}: usando el chat de Control CD. "
                . 'Configurar telegram_chat_id en collection_company_configs.settings para separarlos.'
            );
            return (string) $shared;
        }

        return null;
    }

    /**
     * Chat del administrador de la empresa que ya vinculo su Telegram con el
     * bot de Deuda & Abono (`collection_telegram_links`, linked_at != null).
     *
     * Administrador es, en este orden: role_id 1 o 2 del core (super-admin y
     * admin de empresa, el mismo criterio que usa LoginService para dar
     * `collection_role = admin`), y si ninguno califica, rol `admin` o
     * `manager` en `collection_user_profiles`.
     *
     * Devuelve null si la empresa no tiene vinculos, o si los que tiene son
     * todos de cobradores: en ese caso es preferible no entregar el codigo por
     * Telegram (el admin igual lo ve en el panel de codigos pendientes) antes
     * que mandarselo a quien pidio la eliminacion.
     *
     * Con varios admins vinculados gana el mas reciente, para que revincular
     * un telefono nuevo tenga efecto sin tener que borrar el anterior.
     */
    private function resolveAdminLinkChatId(int $companyId): ?string
    {
        try {
            $links = CollectionTelegramLink::where('company_id', $companyId)
                ->whereNotNull('linked_at')
                ->whereNotNull('user_id')
                ->orderByDesc('linked_at')
                ->get(['chat_id', 'user_id']);

            if ($links->isEmpty()) {
                return null;
            }

            $userIds = $links->pluck('user_id')->map(fn ($v) => (int) $v)->all();

            $adminIds = User::whereIn('id', $userIds)
                ->whereIn('role_id', [1, 2])
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (empty($adminIds)) {
                $adminIds = DB::connection('collection_pgsql')
                    ->table('collection_user_profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('user_id', $userIds)
                    ->whereIn('role', ['admin', 'manager'])
                    ->pluck('user_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
            }

            if (empty($adminIds)) {
                Log::info(
                    "Collection empresa {$companyId}: hay Telegram vinculado, pero ninguno es de un "
                    . 'administrador. No se entrega el codigo por Telegram; queda en el panel de codigos pendientes.'
                );
                return null;
            }

            $match = $links->first(fn ($l) => in_array((int) $l->user_id, $adminIds, true));

            return $match ? (string) $match->chat_id : null;
        } catch (\Throwable $e) {
            // Nunca romper el envio por un fallo resolviendo el destino.
            Log::warning('[collection.telegram] no se pudo resolver el chat del admin: ' . $e->getMessage());
            return null;
        }
    }
}
