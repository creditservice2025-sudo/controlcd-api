<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Token del bot OTP (cliente ControlC&D). Usado por sendMessage/sendOtp.
     * No se reutiliza para notificaciones multi-tenant: cada uso tiene su bot.
     */
    protected $token;
    protected $adminChatId;

    /**
     * Token del bot multi-tenant para notificaciones a empresas. Único bot
     * que envía a TODAS las empresas, cada una identificada por su chat_id.
     * Si está vacío, las notificaciones quedan deshabilitadas globalmente.
     */
    protected $notificationsToken;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->adminChatId = config('services.telegram.admin_chat_id');
        $this->notificationsToken = config('services.telegram.notifications_bot_token');
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

    /**
     * Envía un mensaje al chat de Telegram configurado por la empresa.
     * Usa el bot global pero el chat_id específico de la empresa, garantizando
     * aislamiento: cada empresa recibe únicamente eventos de sus vendedores.
     *
     * No-op silencioso si la empresa no tiene Telegram habilitado o sin chat_id.
     */
    public function sendToCompany(\App\Models\Company $company, string $message, string $type = 'company_notification'): bool
    {
        // Master switch (SA): si la feature no está habilitada para esta
        // empresa, ningún flujo logra mandar mensajes. Esto es lo que
        // permite a Marand controlar el acceso por plan/contrato.
        if (!$company->telegram_feature_enabled) {
            return false;
        }

        if (!$company->telegram_enabled || empty($company->telegram_chat_id)) {
            return false;
        }

        if (!$this->notificationsToken) {
            Log::warning('TELEGRAM_NOTIFICATIONS_BOT_TOKEN no configurado en .env; abortando sendToCompany.');
            return false;
        }

        $chatId = $company->telegram_chat_id;
        $success = false;

        try {
            $response = Http::withoutVerifying()
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$this->notificationsToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ]);

            $success = $response->successful();
            if (!$success) {
                Log::error('Telegram sendToCompany API error', [
                    'company_id' => $company->id,
                    'chat_id' => $chatId,
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram sendToCompany exception', [
                'company_id' => $company->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            \App\Models\TelegramLog::create([
                'chat_id' => $chatId,
                'message' => $message,
                'type' => $type,
                'status' => $success ? 'success' : 'failed',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error guardando log Telegram (sendToCompany): ' . $e->getMessage());
        }

        return $success;
    }

    /**
     * Envía un mensaje de prueba al chat_id de la empresa. Útil para el botón
     * "Probar" en la configuración antes de guardar.
     *
     * A diferencia de sendToCompany, ignora los flags telegram_enabled y
     * telegram_notify_* — basta con tener chat_id configurado.
     */
    public function sendTestToCompany(\App\Models\Company $company): array
    {
        if (empty($company->telegram_chat_id)) {
            return ['success' => false, 'message' => 'La empresa no tiene chat_id configurado.'];
        }

        if (!$this->notificationsToken) {
            return ['success' => false, 'message' => 'Bot de notificaciones no configurado en el servidor (TELEGRAM_NOTIFICATIONS_BOT_TOKEN).'];
        }

        $text = "✅ *Conexión exitosa*\n\n";
        $text .= "Empresa: *{$company->name}*\n";
        $text .= "A partir de ahora recibirás las notificaciones configuradas en este chat.";

        try {
            $response = Http::withoutVerifying()
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$this->notificationsToken}/sendMessage", [
                    'chat_id' => $company->telegram_chat_id,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ]);

            try {
                \App\Models\TelegramLog::create([
                    'chat_id' => $company->telegram_chat_id,
                    'message' => $text,
                    'type' => 'test',
                    'status' => $response->successful() ? 'success' : 'failed',
                ]);
            } catch (\Throwable $e) {
                Log::error('Error guardando log Telegram (sendTestToCompany): ' . $e->getMessage());
            }

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Mensaje de prueba enviado correctamente.'];
            }

            return [
                'success' => false,
                'message' => 'Telegram rechazó el mensaje. Verifica que el chat_id sea correcto y que el usuario haya iniciado conversación con el bot.',
                'detail' => $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error de red: ' . $e->getMessage()];
        }
    }

    /**
     * Notifica creación de cliente nuevo. Resuelve la empresa desde el vendedor
     * del cliente: el aislamiento es estructural (cada empresa solo ve eventos
     * de sus propios vendedores).
     */
    public function notifyNewClient(\App\Models\Client $client): bool
    {
        $seller  = $client->seller;
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_new_client) {
            return false;
        }

        $message = $this->buildNewClientMessage($client, $seller, $company);
        return $this->sendToCompany($company, $message, 'new_client');
    }

    /**
     * Notifica creación de crédito nuevo. Misma garantía de aislamiento.
     */
    public function notifyNewCredit(\App\Models\Credit $credit): bool
    {
        $seller  = $credit->seller;
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_new_credit) {
            return false;
        }

        $message = $this->buildNewCreditMessage($credit, $seller, $company);
        return $this->sendToCompany($company, $message, 'new_credit');
    }

    private function buildNewClientMessage(\App\Models\Client $client, \App\Models\Seller $seller, \App\Models\Company $company): string
    {
        $vendedor = $seller->user->name ?? 'N/D';
        $fecha    = \Carbon\Carbon::now('America/Lima')->format('d/m/Y H:i');

        $m  = "👤 *Cliente nuevo registrado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "🧑 Cliente: *{$client->name}*\n";
        if (!empty($client->dni))   $m .= "🆔 Documento: `{$client->dni}`\n";
        if (!empty($client->phone)) $m .= "📞 Teléfono: {$client->phone}\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🕒 Fecha: {$fecha}";

        return $m;
    }

    /**
     * Genera un token de vinculación de un solo uso para una empresa y devuelve
     * el deep link de Telegram. El usuario hace clic, abre Telegram y al darle
     * START envía /start <token> al bot. El webhook lo recibe, valida y captura
     * el chat_id del autor.
     *
     * Token expira en 15 minutos. Solo puede consumirse una vez.
     */
    public function generateLinkToken(\App\Models\Company $company): array
    {
        $username = config('services.telegram.notifications_bot_username');
        if (empty($username)) {
            throw new \RuntimeException('TELEGRAM_NOTIFICATIONS_BOT_USERNAME no configurado.');
        }

        // Token random URL-safe. 32 bytes = 256 bits de entropía. Mucho más
        // que suficiente para resistir bruteforce en una ventana de 15 min.
        $token = \Illuminate\Support\Str::random(32);

        $company->forceFill([
            'telegram_link_token' => $token,
            'telegram_link_expires_at' => now()->addMinutes(15),
        ])->save();

        $url = "https://t.me/{$username}?start={$token}";

        return [
            'url' => $url,
            'token' => $token,
            'expires_at' => $company->telegram_link_expires_at->toIso8601String(),
            'bot_username' => $username,
        ];
    }

    /**
     * Procesa un /start <token> recibido desde el webhook. Si el token es
     * válido y no expiró, persiste el chat_id en la empresa correspondiente
     * y envía mensaje de confirmación al usuario en Telegram.
     *
     * Retorna ['linked' => bool, 'message' => string].
     */
    public function handleStartLink(string $token, int $chatId, ?string $firstName = null): array
    {
        // Búsqueda atómica: solo una fila puede tener este token.
        $company = \App\Models\Company::where('telegram_link_token', $token)
            ->where('telegram_link_expires_at', '>', now())
            ->first();

        if (!$company) {
            $this->sendReplyToChat(
                $chatId,
                "❌ El enlace de vinculación no es válido o ya expiró.\n\nVuelve al panel de Controll CD y genera uno nuevo."
            );
            return ['linked' => false, 'message' => 'Token inválido o expirado'];
        }

        // Persistir vinculación y limpiar token (one-shot).
        $company->forceFill([
            'telegram_chat_id' => (string) $chatId,
            'telegram_enabled' => true,
            'telegram_link_token' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        $welcome  = "✅ *Vinculación exitosa*\n\n";
        $welcome .= "Empresa: *{$company->name}*\n";
        $welcome .= "A partir de ahora recibirás en este chat las notificaciones que el administrador configure (cliente nuevo, crédito nuevo).\n\n";
        $welcome .= "Puedes desactivar las notificaciones cuando quieras desde el panel.";

        $this->sendReplyToChat($chatId, $welcome);

        return [
            'linked' => true,
            'message' => "Empresa {$company->name} vinculada",
            'company_id' => $company->id,
        ];
    }

    /**
     * Helper interno: envía un texto plano (Markdown) al chat_id especificado
     * usando el bot de notificaciones. Sin sellos de empresa, solo mensajería.
     */
    private function sendReplyToChat(int $chatId, string $text): void
    {
        if (!$this->notificationsToken) {
            Log::warning('sendReplyToChat sin TELEGRAM_NOTIFICATIONS_BOT_TOKEN.');
            return;
        }

        try {
            Http::withoutVerifying()
                ->timeout(5)
                ->post("https://api.telegram.org/bot{$this->notificationsToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ]);
        } catch (\Throwable $e) {
            Log::error('Telegram sendReplyToChat exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifica creación de gasto. Resuelve la empresa desde el seller asociado
     * al user del gasto (expenses.user_id → sellers.user_id → companies).
     */
    public function notifyNewExpense(\App\Models\Expense $expense): bool
    {
        $seller = \App\Models\Seller::where('user_id', $expense->user_id)->with('company.user')->first();
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_new_expense) {
            return false;
        }

        $message = $this->buildNewExpenseMessage($expense, $seller, $company);
        return $this->sendToCompany($company, $message, 'new_expense');
    }

    /**
     * Notifica eliminación de gasto. Se llama ANTES del soft-delete (cuando el
     * registro aún tiene relaciones cargadas) o con $expense ya cargado.
     */
    public function notifyDeletedExpense(\App\Models\Expense $expense): bool
    {
        $seller = \App\Models\Seller::where('user_id', $expense->user_id)->with('company.user')->first();
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_deleted_expense) {
            return false;
        }

        $message = $this->buildDeletedExpenseMessage($expense, $seller, $company);
        return $this->sendToCompany($company, $message, 'deleted_expense');
    }

    /**
     * Notifica eliminación de crédito. La instancia $credit aún se conserva
     * en memoria con sus relaciones aunque ya esté eliminada en BD.
     */
    public function notifyDeletedCredit(\App\Models\Credit $credit): bool
    {
        $seller  = $credit->seller;
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_deleted_credit) {
            return false;
        }

        $message = $this->buildDeletedCreditMessage($credit, $seller, $company);
        return $this->sendToCompany($company, $message, 'deleted_credit');
    }

    private function buildNewExpenseMessage(\App\Models\Expense $expense, \App\Models\Seller $seller, \App\Models\Company $company): string
    {
        $vendedor = $seller->user->name ?? 'N/D';
        $currency = $seller->city->country->currency ?? '';
        $valor    = number_format((float) $expense->value, 2);
        $desc     = $expense->description ?? 's/d';
        $fecha    = \Carbon\Carbon::now('America/Lima')->format('d/m/Y H:i');

        $m  = "💸 *Gasto registrado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "📝 Descripción: {$desc}\n";
        $m .= "💵 Monto: *{$currency} {$valor}*\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🕒 Fecha: {$fecha}";

        return $m;
    }

    private function buildDeletedExpenseMessage(\App\Models\Expense $expense, \App\Models\Seller $seller, \App\Models\Company $company): string
    {
        $vendedor = $seller->user->name ?? 'N/D';
        $currency = $seller->city->country->currency ?? '';
        $valor    = number_format((float) $expense->value, 2);
        $desc     = $expense->description ?? 's/d';
        $fecha    = \Carbon\Carbon::now('America/Lima')->format('d/m/Y H:i');

        $m  = "🗑️ *Gasto eliminado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "📝 Descripción: {$desc}\n";
        $m .= "💵 Monto: *{$currency} {$valor}*\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🕒 Fecha: {$fecha}";

        return $m;
    }

    private function buildDeletedCreditMessage(\App\Models\Credit $credit, \App\Models\Seller $seller, \App\Models\Company $company): string
    {
        $vendedor = $seller->user->name ?? 'N/D';
        $cliente  = $credit->client->name ?? 'N/D';
        $currency = $seller->city->country->currency ?? '';
        $valor    = number_format((float) $credit->credit_value, 2);
        $fecha    = \Carbon\Carbon::now('America/Lima')->format('d/m/Y H:i');

        $m  = "🗑️ *Crédito eliminado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "🧑 Cliente: *{$cliente}*\n";
        $m .= "💵 Monto: *{$currency} {$valor}*\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🕒 Fecha: {$fecha}";

        return $m;
    }

    private function buildNewCreditMessage(\App\Models\Credit $credit, \App\Models\Seller $seller, \App\Models\Company $company): string
    {
        $vendedor    = $seller->user->name ?? 'N/D';
        $cliente     = $credit->client->name ?? 'N/D';
        $currency    = $seller->city->country->currency ?? '';
        $valor       = number_format((float) $credit->credit_value, 2);
        $cuotas      = $credit->number_installments ?? '—';
        $frecuencia  = $credit->payment_frequency ?? '—';
        $fecha       = \Carbon\Carbon::now('America/Lima')->format('d/m/Y H:i');

        $m  = "💰 *Crédito nuevo otorgado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "🧑 Cliente: *{$cliente}*\n";
        $m .= "💵 Monto: *{$currency} {$valor}*\n";
        $m .= "📅 Cuotas: {$cuotas} ({$frecuencia})\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🕒 Fecha: {$fecha}";

        return $m;
    }
}
