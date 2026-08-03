<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint TEMPORAL de diagnostico para la incompatibilidad de telefono.
 *
 * El APK envia migas del flujo (abrir camara, volver de camara, intentos de
 * GPS, timeout de geocoder, guardar/bloqueado) y, en frio, avisa si el WebView
 * murio a mitad de una captura. Aca formateamos el evento y lo reenviamos a un
 * chat de Telegram que mira el desarrollador en vivo. Ademas queda registrado
 * en el log diario por si hay que revisar la secuencia completa.
 *
 * Es desechable: se borra (controller + ruta + bot + env) al cerrar la prueba.
 */
class DiagnosticsController extends Controller
{
    public function store(Request $request)
    {
        // Kill switch: si el diagnostico esta apagado, descartar en silencio
        // (el APK sigue enviando, pero no reenviamos a Telegram ni logueamos).
        if (!config('services.telegram.diag_enabled')) {
            return response()->json(['success' => true, 'skipped' => true]);
        }

        try {
            $user = $request->user();

            $data = [
                'event'    => (string) $request->input('event', 'unknown'),
                'level'    => (string) $request->input('level', 'info'), // info|warn|error
                'detail'   => $request->input('detail'),
                'session'  => (string) $request->input('session', '-'),
                'device'   => $request->input('device', []),
                'user_id'  => $user ? $user->id : ($request->input('user_id') ?? 'guest'),
                'user_name' => $user ? ($user->name ?? null) : $request->input('user_name'),
                'app_ts'   => $request->input('app_ts'),
                'ip'       => $request->ip(),
                'ua'       => $request->header('User-Agent'),
            ];

            // Log completo del lado servidor (respaldo por si Telegram falla).
            // Acá sí entra TODO, incluidas las migas: sirve para reconstruir la
            // secuencia de un incidente sin depender del chat.
            Log::channel('daily')->info('DIAG: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

            // AL CHAT SOLO VAN LOS INCIDENTES.
            //
            // Antes se reenviaba cada evento, incluidos "app_arranque",
            // "captura_inicio/fin" y cada cambio de background: con 163 rutas
            // eso llenaba el chat de mensajes de operación normal y el fallo
            // que se está buscando quedaba enterrado. El APK en producción
            // sigue enviando esos eventos, así que el filtro va acá: permite
            // encender el diagnóstico sin actualizar la app y sin ruido.
            $nivelesReportables = (array) config('services.telegram.diag_levels', ['error']);

            if (in_array($data['level'], $nivelesReportables, true)) {
                $this->sendToTelegram($data);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            // Nunca romper al cliente por un fallo de diagnostico.
            Log::error('DiagnosticsController error: ' . $e->getMessage());
            return response()->json(['success' => false], 200);
        }
    }

    private function sendToTelegram(array $d): void
    {
        $token  = config('services.telegram.diag_bot_token');
        $chatId = config('services.telegram.diag_chat_id');

        if (!$token || !$chatId) {
            Log::warning('DIAG: telegram.diag no configurado (token/chat_id).');
            return;
        }

        $emoji = match ($d['level']) {
            'error' => '🔴',
            'warn'  => '🟡',
            default => '🔵',
        };

        $dev = $d['device'] ?? [];
        $model = trim(($dev['manufacturer'] ?? '') . ' ' . ($dev['model'] ?? ''));
        $os = trim(($dev['osVersion'] ?? '') !== '' ? "Android {$dev['osVersion']}" : '');
        $wv = isset($dev['webViewVersion']) ? "WebView {$dev['webViewVersion']}" : '';
        $mem = isset($dev['memUsed']) ? "RAM libre: {$dev['memUsed']}" : '';

        $lines = [
            "{$emoji} *DIAG · {$d['event']}*",
            "👤 " . ($d['user_name'] ? "{$d['user_name']} (#{$d['user_id']})" : "#{$d['user_id']}"),
            "🆔 sesion: `{$d['session']}`",
        ];
        if ($model !== '' || $os !== '') {
            $lines[] = "📱 " . trim("{$model}  {$os}");
        }
        if ($wv !== '' || $mem !== '') {
            $lines[] = "🧩 " . trim("{$wv}  {$mem}");
        }
        if (!empty($d['detail'])) {
            $detail = is_array($d['detail']) ? json_encode($d['detail'], JSON_UNESCAPED_UNICODE) : (string) $d['detail'];
            $lines[] = "📝 " . mb_substr($detail, 0, 500);
        }
        if (!empty($d['app_ts'])) {
            $lines[] = "🕒 " . $d['app_ts'];
        }

        $text = implode("\n", $lines);

        try {
            Http::withoutVerifying()
                ->timeout(8)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Log::error('DIAG: fallo enviando a Telegram: ' . $e->getMessage());
        }
    }
}
