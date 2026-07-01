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
    public function sendOtp($otp, $sellerName = '', $clientName = '', $editor = null, $changes = [])
    {
        $message = "🔐 *VERIFICACIÓN REQUERIDA*\n\n";

        // Indicar QUIÉN realiza la edición. Si es el Supervisor (rol 6), se
        // marca como "Solicitud del supervisor" y se muestra aparte el vendedor
        // dueño del cliente. Si lo hace un admin, se indica también. Si lo hace
        // el propio vendedor (o no hay editor), se muestra solo el vendedor.
        $editorRole = (int) ($editor->role_id ?? 0);
        if ($editor && $editorRole === 6) {
            $message .= "🛡️ *Solicitud del supervisor:* {$editor->name}\n";
            if (!empty($sellerName)) {
                $message .= "📍 *Vendedor:* {$sellerName}\n";
            }
        } elseif ($editor && in_array($editorRole, [1, 2], true)) {
            $message .= "👤 *Editado por (admin):* {$editor->name}\n";
            if (!empty($sellerName)) {
                $message .= "📍 *Vendedor:* {$sellerName}\n";
            }
        } else {
            $message .= "📍 *Vendedor:* " . ($sellerName ?: ($editor->name ?? 'N/D')) . "\n";
        }

        $message .= "👥 *Cliente:* {$clientName}\n";

        // Detalle de QUÉ se cambia (campos antes→después y fotos).
        $detail = $this->buildChangesDetail($changes);
        if ($detail !== '') {
            $message .= "\n📝 *Cambios solicitados:*\n{$detail}\n";
        }

        // Tipo de solicitud según lo que cambió.
        $hasFields = !empty($changes['fields']);
        $hasPhotos = !empty($changes['photos']);
        $tipo = ($hasFields && $hasPhotos)
            ? 'Cambio de datos y fotos'
            : ($hasPhotos ? 'Cambio de fotos' : ($hasFields ? 'Cambio de datos' : 'Cambio de datos/fotos'));

        $message .= "\nSolicitud: *{$tipo}*\n";
        $message .= "Código de aceptación:\n";
        $message .= "👉 `{$otp}`\n\n";
        $message .= "⚠️ Este código expira en 5 minutos.";

        return $this->sendMessage($message, 'otp');
    }

    /**
     * Arma el detalle de cambios para el mensaje de verificación. Sanea los
     * valores para no romper el Markdown de Telegram.
     */
    private function buildChangesDetail($changes): string
    {
        if (!is_array($changes)) {
            return '';
        }
        $clean = fn($v) => str_replace(['*', '_', '`', '['], '', (string) $v);
        $lines = [];

        foreach (($changes['fields'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $label = $clean($f['label'] ?? 'Campo');
            $old   = $clean($f['old'] ?? '');
            $new   = $clean($f['new'] ?? '');
            $lines[] = "• {$label}: {$old} → {$new}";
        }
        foreach (($changes['photos'] ?? []) as $p) {
            $lines[] = "• 📷 " . $clean($p);
        }

        return implode("\n", $lines);
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

        // Quiet hours: si el "ahora" (en la zona del primer vendedor de la
        // empresa) cae dentro del rango configurado, no enviar. Útil para
        // no despertar al admin con notificaciones nocturnas.
        if ($this->isInQuietHours($company)) {
            \App\Models\TelegramLog::create([
                'company_id' => $company->id,
                'chat_id' => $company->telegram_chat_id,
                'message' => $message,
                'type' => $type,
                'status' => 'skipped_quiet_hours',
            ]);
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
                'company_id' => $company->id,
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
                    'company_id' => $company->id,
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
     * Verifica si "ahora" está dentro de la ventana de quiet hours configurada
     * para la empresa. Usa la zona horaria del primer vendedor de la empresa
     * (o America/Lima como fallback). El rango puede atravesar medianoche.
     */
    private function isInQuietHours(\App\Models\Company $company): bool
    {
        $start = $company->telegram_quiet_hours_start;
        $end   = $company->telegram_quiet_hours_end;

        if (empty($start) || empty($end) || $start === $end) {
            return false;
        }

        $tz = $this->resolveCompanyTimezone($company);
        $now = \Carbon\Carbon::now($tz);
        $nowMin = $now->hour * 60 + $now->minute;

        // Soporta tanto Carbon como string "HH:mm:ss" o "HH:mm".
        $startMin = $this->timeToMinutes((string) $start);
        $endMin   = $this->timeToMinutes((string) $end);

        if ($startMin === null || $endMin === null) return false;

        if ($startMin < $endMin) {
            // Rango dentro del día: ej 13:00 - 14:00
            return $nowMin >= $startMin && $nowMin < $endMin;
        }
        // Rango que atraviesa medianoche: ej 22:00 - 07:00
        return $nowMin >= $startMin || $nowMin < $endMin;
    }

    private function timeToMinutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) return null;
        return ((int) $m[1]) * 60 + ((int) $m[2]);
    }

    private function resolveCompanyTimezone(\App\Models\Company $company): string
    {
        try {
            $seller = $company->sellers()->with('city.country')->first();
            $tz = $seller?->city?->country?->timezone;
            return $tz ?: 'America/Lima';
        } catch (\Throwable $e) {
            return 'America/Lima';
        }
    }

    /**
     * Notifica creación de cliente nuevo. Resuelve la empresa desde el vendedor
     * del cliente: el aislamiento es estructural (cada empresa solo ve eventos
     * de sus propios vendedores). Despacha un Job para no bloquear el request.
     */
    public function notifyNewClient(\App\Models\Client $client, ?\App\Models\Credit $credit = null): bool
    {
        $seller  = $client->seller;
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_new_client) {
            return false;
        }

        $message = $this->buildNewClientMessage($client, $seller, $company, $credit);
        $this->dispatchSend($company->id, $message, 'new_client');
        return true;
    }

    /**
     * Notifica que un cliente EXISTENTE fue editado. Se usa especialmente para
     * dejar constancia cuando el Supervisor (rol 6) edita el cliente del
     * vendedor que supervisa: el mensaje lo marca como "Solicitud del
     * supervisor". Reutiliza el toggle de notificación de clientes de la
     * empresa (telegram_notify_new_client).
     */
    public function notifyUpdatedClient(\App\Models\Client $client, \App\Models\User $editor, $changes = []): bool
    {
        $seller  = $client->seller;
        $company = $seller?->company;

        if (!$company || !$company->telegram_notify_new_client) {
            return false;
        }

        $message = $this->buildUpdatedClientMessage($client, $seller, $company, $editor, $changes);
        $this->dispatchSend($company->id, $message, 'updated_client');
        return true;
    }

    private function buildUpdatedClientMessage(\App\Models\Client $client, \App\Models\Seller $seller, \App\Models\Company $company, \App\Models\User $editor, $changes = []): string
    {
        $vendedor   = $seller->user->name ?? 'N/D';
        $editorName = $editor->name ?? 'N/D';
        $tz         = $seller->city->country->timezone ?? 'America/Lima';
        $fecha      = \Carbon\Carbon::now($tz)->format('d/m/Y H:i');

        $m  = "✏️ *Cliente actualizado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "🧑 Cliente: *{$client->name}*\n";
        if (!empty($client->dni))   $m .= "🆔 Documento: `{$client->dni}`\n";
        if (!empty($client->phone)) $m .= "📞 Teléfono: {$client->phone}\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";

        // Quién realizó la edición.
        $editorRole = (int) ($editor->role_id ?? 0);
        if ($editorRole === 6) {
            $m .= "🛡️ *Solicitud del supervisor: {$editorName}*\n";
        } elseif (in_array($editorRole, [1, 2], true)) {
            $m .= "👤 *Editado por (administrador): {$editorName}*\n";
        } else {
            $m .= "✍️ Editado por: {$editorName}\n";
        }

        // Detalle de qué cambió (campos antes→después y fotos).
        $detail = $this->buildChangesDetail($changes);
        if ($detail !== '') {
            $m .= "\n📝 *Cambios:*\n{$detail}\n";
        }

        $m .= "\n🕒 Fecha: {$fecha}";

        return $m;
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
        $this->dispatchSend($company->id, $message, 'new_credit');
        return true;
    }

    /**
     * Despacha el Job de envío. Centraliza la conversión del "ahora envía"
     * sincrónico a la cola async.
     */
    private function dispatchSend(int $companyId, string $message, string $type): void
    {
        try {
            \App\Jobs\SendTelegramNotificationJob::dispatch($companyId, $message, $type);
        } catch (\Throwable $e) {
            // Si la cola está rota, fallback a envío síncrono para no perder
            // el evento. Loguear para que se detecte el problema de infra.
            Log::warning('[telegram] dispatch falló, fallback a sync', [
                'error' => $e->getMessage(),
            ]);
            $company = \App\Models\Company::find($companyId);
            if ($company) {
                $this->sendToCompany($company, $message, $type);
            }
        }
    }

    private function buildNewClientMessage(\App\Models\Client $client, \App\Models\Seller $seller, \App\Models\Company $company, ?\App\Models\Credit $credit = null): string
    {
        $vendedor = $seller->user->name ?? 'N/D';
        $tz       = $seller->city->country->timezone ?? 'America/Lima';
        $fecha    = \Carbon\Carbon::now($tz)->format('d/m/Y H:i');

        $m  = "👤 *Cliente nuevo registrado*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "🧑 Cliente: *{$client->name}*\n";
        if (!empty($client->dni))   $m .= "🆔 Documento: `{$client->dni}`\n";
        if (!empty($client->phone)) $m .= "📞 Teléfono: {$client->phone}\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🕒 Fecha: {$fecha}";

        // Crédito inicial: se incluye en el mismo mensaje cuando el cliente
        // se crea junto con su crédito (caso típico). Si no hay crédito
        // bundleado (alta manual sin crédito), simplemente no se agrega.
        if ($credit) {
            $currency      = $seller->city->country->currency ?? '';
            $creditValue   = (float) $credit->credit_value;
            $interestRate  = (float) ($credit->total_interest ?? 0);
            $monto         = number_format($creditValue, 2);

            // Total a pagar: si el campo `total_amount` no fue persistido
            // (ej. flujo de alta de cliente que no lo calcula), lo derivamos
            // en runtime desde credit_value + interés. Mostramos siempre algo
            // útil en la notificación sin tocar la lógica del módulo financiero.
            $totalAmountDB = (float) ($credit->total_amount ?? 0);
            $totalCalc     = $creditValue * (1 + $interestRate / 100);
            $totalPagar    = number_format($totalAmountDB > 0 ? $totalAmountDB : $totalCalc, 2);

            $cuotas      = $credit->number_installments ?? '—';
            $frecuencia  = $credit->payment_frequency ?? '—';
            $poliza      = (float) ($credit->micro_insurance_amount ?? 0);
            $primerCuota = $credit->first_quota_date
                ? \Carbon\Carbon::parse($credit->first_quota_date)->format('d/m/Y')
                : null;

            $m .= "\n\n💰 *Crédito inicial*\n";
            $m .= "💵 Monto: *{$currency} {$monto}*\n";
            $m .= "📅 Cuotas: {$cuotas} ({$frecuencia})\n";
            if ($interestRate > 0) {
                $m .= "📊 Interés: " . number_format($interestRate, 2) . "%\n";
            }
            $m .= "🧾 Total a pagar: *{$currency} {$totalPagar}*";
            if ($poliza > 0) {
                $m .= "\n🛡️ Microseguro: {$currency} " . number_format($poliza, 2);
            }
            if ($primerCuota) {
                $m .= "\n📆 Primera cuota: {$primerCuota}";
            }
        }

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
        $plainToken = \Illuminate\Support\Str::random(32);

        // Defense in depth: si la BD se filtra, los tokens NO son utilizables
        // (sha256 unidireccional). El token "en claro" solo lo conoce el
        // usuario que lo abre desde el panel y Telegram cuando recibe /start.
        // Usamos sha256 (no bcrypt) porque queremos lookup rápido — bcrypt
        // requiere iterar todos los hashes activos.
        $hashedToken = hash('sha256', $plainToken);

        $company->forceFill([
            'telegram_link_token' => $hashedToken,
            'telegram_link_expires_at' => now()->addMinutes(15),
        ])->save();

        $url = "https://t.me/{$username}?start={$plainToken}";

        return [
            'url' => $url,
            'token' => $plainToken, // se devuelve en claro solo en este momento
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
        // Recibimos el token "en claro" desde el comando /start de Telegram.
        // Lo hasheamos para comparar contra lo guardado en BD.
        $hashedToken = hash('sha256', $token);

        // Búsqueda atómica: solo una fila puede tener este hash de token.
        $company = \App\Models\Company::where('telegram_link_token', $hashedToken)
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
        $this->dispatchSend($company->id, $message, 'new_expense');
        return true;
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
        $this->dispatchSend($company->id, $message, 'deleted_expense');
        return true;
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
        $this->dispatchSend($company->id, $message, 'deleted_credit');
        return true;
    }

    /**
     * Notifica que el SUPERVISOR registró un pago sobre el vendedor que
     * supervisa. Deja constancia como "Solicitud del supervisor".
     */
    public function notifyNewPayment(\App\Models\Payment $payment, \App\Models\User $editor): bool
    {
        return $this->notifyPaymentAction($payment, $editor, 'registrado');
    }

    /**
     * Notifica que el SUPERVISOR eliminó un pago del vendedor que supervisa.
     */
    public function notifyDeletedPayment(\App\Models\Payment $payment, \App\Models\User $editor): bool
    {
        return $this->notifyPaymentAction($payment, $editor, 'eliminado');
    }

    private function notifyPaymentAction(\App\Models\Payment $payment, \App\Models\User $editor, string $accion): bool
    {
        $credit  = $payment->credit;
        $seller  = $credit?->seller;
        $company = $seller?->company;

        if (!$company) {
            return false;
        }

        $message = $this->buildPaymentActionMessage($payment, $credit, $seller, $company, $editor, $accion);
        $this->dispatchSend($company->id, $message, 'payment_' . $accion);
        return true;
    }

    private function buildPaymentActionMessage($payment, $credit, $seller, $company, \App\Models\User $editor, string $accion): string
    {
        $vendedor   = $seller->user->name ?? 'N/D';
        $editorName = $editor->name ?? 'N/D';
        $cliente    = $credit?->client->name ?? 'N/D';
        $currency   = $seller->city->country->currency ?? '';
        $monto      = number_format((float) $payment->amount, 2);
        $tz         = $seller->city->country->timezone ?? 'America/Lima';
        $fecha      = \Carbon\Carbon::now($tz)->format('d/m/Y H:i');

        $icon   = $accion === 'eliminado' ? '🗑️' : '💵';
        $titulo = $accion === 'eliminado' ? 'Pago eliminado' : 'Pago registrado';
        $creditNo = str_pad((string) ($credit->id ?? ''), 7, '0', STR_PAD_LEFT);

        $m  = "{$icon} *{$titulo}*\n\n";
        $m .= "🏢 Empresa: *{$company->name}*\n";
        $m .= "👥 Cliente: *{$cliente}*\n";
        $m .= "🧾 Crédito: #{$creditNo}\n";
        $m .= "💰 Monto: {$currency}{$monto}\n";
        $m .= "🛵 Vendedor: {$vendedor}\n";
        $m .= "🛡️ *Solicitud del supervisor: {$editorName}*\n";
        $m .= "🕒 Fecha: {$fecha}";

        return $m;
    }

    private function buildNewExpenseMessage(\App\Models\Expense $expense, \App\Models\Seller $seller, \App\Models\Company $company): string
    {
        $vendedor = $seller->user->name ?? 'N/D';
        $currency = $seller->city->country->currency ?? '';
        $valor    = number_format((float) $expense->value, 2);
        $desc     = $expense->description ?? 's/d';
        $tz       = $seller->city->country->timezone ?? 'America/Lima';
        $fecha    = \Carbon\Carbon::now($tz)->format('d/m/Y H:i');

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
        $tz       = $seller->city->country->timezone ?? 'America/Lima';
        $fecha    = \Carbon\Carbon::now($tz)->format('d/m/Y H:i');

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
        $tz       = $seller->city->country->timezone ?? 'America/Lima';
        $fecha    = \Carbon\Carbon::now($tz)->format('d/m/Y H:i');

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
