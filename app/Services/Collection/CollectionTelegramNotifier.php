<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionCompanyConfig;
use App\Models\Company;
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
            $response = Http::withoutVerifying()->post(
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
}
