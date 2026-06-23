<?php

namespace App\Services\Collection;

use App\Models\Company;
use App\Models\TelegramLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper sobre la API de Telegram para uso exclusivo del módulo Collection.
 *
 * No modifica TelegramService global (que va al admin del sistema). Este
 * notificador resuelve el chat_id por empresa (companies.telegram_chat_id):
 *  - Si la empresa tiene chat configurado → envía al chat de esa empresa.
 *  - Si no tiene → no envía Telegram (silencioso). El usuario aún recibe
 *    la notificación in-app vía Laravel Notifications.
 */
class CollectionTelegramNotifier
{
    protected ?string $token;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
    }

    /**
     * Envía un mensaje al chat configurado para la empresa $companyId.
     * Devuelve true si se envió, false si no había chat o falló.
     */
    public function sendToCompany(int $companyId, string $message, string $type = 'collection_notification'): bool
    {
        if (!$this->token) {
            Log::warning('CollectionTelegramNotifier: TELEGRAM_BOT_TOKEN no configurado.');
            return false;
        }

        $company = Company::find($companyId);
        if (!$company || empty($company->telegram_chat_id)) {
            // Silencioso: la empresa no configuró Telegram. Solo in-app.
            return false;
        }

        $chatId = $company->telegram_chat_id;

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
}
