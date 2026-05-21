<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recibe los updates que Telegram POSTea al webhook. Es público (no requiere
 * Bearer) porque viene de servidores de Telegram, no de un usuario. La
 * autenticidad se valida por el header X-Telegram-Bot-Api-Secret-Token,
 * que solo conocemos nosotros y Telegram (pegado al hacer setWebhook).
 */
class TelegramWebhookController extends Controller
{
    use ApiResponse;

    protected $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    public function handle(Request $request)
    {
        // 1. Validar el secret del header. Si no coincide, rechazar.
        $expected = config('services.telegram.webhook_secret');
        $received = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($expected)) {
            Log::error('[telegram.webhook] TELEGRAM_WEBHOOK_SECRET no configurado; rechazando.');
            return response()->json(['ok' => false], 503);
        }

        if (!is_string($received) || !hash_equals($expected, $received)) {
            Log::warning('[telegram.webhook] secret inválido', ['ip' => $request->ip()]);
            return response()->json(['ok' => false], 401);
        }

        // 2. Parsear el update. Solo nos interesan mensajes con texto.
        $update = $request->all();
        $message = $update['message'] ?? null;

        if (!$message || empty($message['text']) || empty($message['chat']['id'])) {
            // No es un mensaje de texto (puede ser edited_message, etc).
            // Devolvemos 200 para que Telegram no reintente.
            return response()->json(['ok' => true]);
        }

        $text   = (string) $message['text'];
        $chatId = (int) $message['chat']['id'];
        $first  = $message['from']['first_name'] ?? null;

        // 3. Reconocer comando /start <token>
        if (preg_match('/^\/start\s+([A-Za-z0-9]{20,64})\s*$/', $text, $m)) {
            $token = $m[1];
            try {
                $result = $this->telegram->handleStartLink($token, $chatId, $first);
                Log::info('[telegram.webhook] /start link procesado', [
                    'chat_id' => $chatId,
                    'linked' => $result['linked'] ?? false,
                ]);
            } catch (\Throwable $e) {
                Log::error('[telegram.webhook] error handleStartLink', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
            return response()->json(['ok' => true]);
        }

        // 4. /start sin token: dar pista al usuario para que vuelva al panel.
        if (preg_match('/^\/start\s*$/', $text)) {
            $this->replyHelp($chatId);
            return response()->json(['ok' => true]);
        }

        // 5. Cualquier otro texto: respuesta genérica de ayuda.
        $this->replyHelp($chatId);
        return response()->json(['ok' => true]);
    }

    private function replyHelp(int $chatId): void
    {
        $help  = "👋 Hola! Soy el bot de notificaciones de *Controll CD*.\n\n";
        $help .= "Para activar las notificaciones de tu empresa, abre el panel de Controll CD, ve a *Empresas*, hace clic en el ícono de Telegram y presiona *Vincular mi Telegram*.\n\n";
        $help .= "Eso generará un enlace que automáticamente conectará este chat con tu empresa.";

        $expected = config('services.telegram.notifications_bot_token');
        if (empty($expected)) return;

        try {
            \Illuminate\Support\Facades\Http::withoutVerifying()
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$expected}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $help,
                    'parse_mode' => 'Markdown',
                ]);
        } catch (\Throwable $e) {
            Log::error('[telegram.webhook] replyHelp error', ['error' => $e->getMessage()]);
        }
    }
}
