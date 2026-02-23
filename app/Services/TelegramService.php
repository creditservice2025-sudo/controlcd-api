<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected $token;
    protected $adminChatId;

    public function __construct()
    {
        $this->token = env('TELEGRAM_BOT_TOKEN');
        $this->adminChatId = env('TELEGRAM_ADMIN_CHAT_ID');
    }

    /**
     * Send a message to the configured admin chat ID
     */
    public function sendMessage($message, $type = 'notification')
    {
        if (!$this->token || !$this->adminChatId) {
            Log::warning('Telegram configuration missing (Token or Chat ID).');
            return false;
        }

        try {
            $response = Http::withoutVerifying()->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'chat_id' => $this->adminChatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

            if (!$response->successful()) {
                Log::error('Telegram API error: ' . $response->body());
            }

            // Log to database
            try {
                \App\Models\TelegramLog::create([
                    'chat_id' => $this->adminChatId,
                    'message' => $message,
                    'type' => $type,
                    'status' => $response->successful() ? 'success' : 'failed',
                ]);
            } catch (\Exception $e) {
                Log::error('Error saving Telegram log: ' . $e->getMessage());
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a formatted OTP message
     */
    public function sendOtp($otp, $sellerName = '', $clientName = '')
    {
        $message = "🔐 *VERIFICACIÓN REQUERIDA*\n\n";
        $message .= "📍 *Vendedor:* {$sellerName}\n";
        $message .= "👥 *Cliente:* {$clientName}\n\n";
        $message .= "Solicitud: *Cambio de fotos*\n";
        $message .= "Código de aceptación:\n";
        $message .= "👉 `{$otp}`\n\n";
        $message .= "⚠️ Este código expira en 5 minutos.";

        return $this->sendMessage($message, 'otp');
    }
}
