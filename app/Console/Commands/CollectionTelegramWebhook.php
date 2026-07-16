<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Gestiona el webhook del bot de Telegram de Collection sin tener que armar
 * curl a mano. Lee el token y el secreto de config('services.telegram_collection').
 *
 * Uso:
 *   php artisan collection:telegram-webhook set --url=https://TU_DOMINIO/api/collection/telegram/webhook
 *   php artisan collection:telegram-webhook info
 *   php artisan collection:telegram-webhook delete
 */
class CollectionTelegramWebhook extends Command
{
    protected $signature = 'collection:telegram-webhook
        {action=info : set | info | delete}
        {--url= : URL pública HTTPS del webhook (para "set")}';

    protected $description = 'Registra/consulta/borra el webhook del bot de Telegram de Collection';

    public function handle(): int
    {
        $token = config('services.telegram_collection.bot_token');
        if (!$token) {
            $this->error('Falta TELEGRAM_COLLECTION_BOT_TOKEN en el .env (¿corriste config:clear?).');
            return self::FAILURE;
        }
        $base = "https://api.telegram.org/bot{$token}";
        $action = $this->argument('action');

        if ($action === 'info') {
            $info = Http::withoutVerifying()->get("{$base}/getWebhookInfo")->json();
            $this->line(json_encode($info['result'] ?? $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        if ($action === 'delete') {
            $res = Http::withoutVerifying()->post("{$base}/deleteWebhook", ['drop_pending_updates' => true])->json();
            $this->info($res['ok'] ?? false ? 'Webhook eliminado.' : 'No se pudo eliminar: ' . json_encode($res));
            return self::SUCCESS;
        }

        if ($action === 'set') {
            $url = $this->option('url');
            if (!$url) {
                $this->error('Falta --url=https://TU_DOMINIO/api/collection/telegram/webhook');
                return self::FAILURE;
            }
            $secret = config('services.telegram_collection.webhook_secret');
            $payload = ['url' => $url, 'allowed_updates' => ['message']];
            if ($secret) {
                $payload['secret_token'] = $secret;
            } else {
                $this->warn('Ojo: TELEGRAM_COLLECTION_WEBHOOK_SECRET vacío → el webhook quedará sin validación de secreto.');
            }
            $res = Http::withoutVerifying()->post("{$base}/setWebhook", $payload)->json();
            if ($res['ok'] ?? false) {
                $this->info("Webhook registrado en: {$url}");
                if ($secret) $this->line('Con secreto (X-Telegram-Bot-Api-Secret-Token) activado.');
            } else {
                $this->error('Falló setWebhook: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        $this->error("Acción desconocida: {$action}. Usá set | info | delete.");
        return self::FAILURE;
    }
}
