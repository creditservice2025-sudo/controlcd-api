<?php

namespace App\Http\Controllers\Collection;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionTelegramBotService;
use Illuminate\Http\Request;

/**
 * Webhook del bot de Telegram de Collection (carga de gastos por el cobrador).
 * Público (Telegram lo llama); se valida con el secreto compartido.
 */
class CollectionTelegramBotController extends Controller
{
    public function webhook(Request $request, CollectionTelegramBotService $bot)
    {
        $secret = config('services.telegram_collection.webhook_secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['ok' => false], 403);
        }

        // El servicio maneja sus propios errores; siempre respondemos 200 para
        // que Telegram no reintente en loop.
        $bot->handleUpdate($request->all());

        return response()->json(['ok' => true]);
    }
}
