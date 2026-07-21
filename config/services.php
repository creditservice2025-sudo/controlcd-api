<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        // Bot usado para el OTP de verificación de fotos (cliente ControlC&D).
        // No se toca: mantiene compatibilidad con el flujo actual.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),

        // Bot multi-tenant: notificaciones de cliente/crédito a cada empresa.
        // Se crea aparte vía @BotFather y se gestiona desde la plataforma.
        // Cada empresa configura SU chat_id; el bot es uno solo para todas.
        'notifications_bot_token' => env('TELEGRAM_NOTIFICATIONS_BOT_TOKEN'),

        // Username del bot de notificaciones SIN el @ (ej: ControlCDNotifEmpBot).
        // Se usa para construir el deep link t.me/<username>?start=<token>.
        'notifications_bot_username' => env('TELEGRAM_NOTIFICATIONS_BOT_USERNAME', 'ControlCDNotifEmpBot'),

        // Secret compartido con Telegram para validar que los webhooks
        // vienen realmente de Telegram (header X-Telegram-Bot-Api-Secret-Token).
        // Se genera random la primera vez y se pega al hacer setWebhook.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

        // Bot TEMPORAL de diagnostico (incompatibilidad de telefono). Recibe la
        // telemetria del APK y la reenvia a un chat que mira el desarrollador.
        // Es desechable: quitar el bloque y las env cuando termine la prueba.
        'diag_bot_token' => env('TELEGRAM_DIAG_BOT_TOKEN'),
        'diag_chat_id' => env('TELEGRAM_DIAG_CHAT_ID'),
        // Kill switch: si es false, el endpoint /diagnostics descarta todo
        // (no reenvia a Telegram ni loguea). Permite apagar el ruido sin
        // recompilar el APK, y re-encender solo para medir el fix en campo.
        'diag_enabled' => env('TELEGRAM_DIAG_ENABLED', false),
    ],

];
