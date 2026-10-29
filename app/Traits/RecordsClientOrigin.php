<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Registra desde qué app se creó un movimiento (client_platform y
 * client_app_version). Solo actúa al CREAR y solo dentro de un request HTTP:
 * lo que crean comandos, jobs en cola o scripts queda en NULL, porque no vino
 * de ninguna app.
 *
 * No toca montos ni ningún otro campo: es solo auditoría.
 */
trait RecordsClientOrigin
{
    public static function bootRecordsClientOrigin(): void
    {
        static::creating(function ($model) {
            if ($model->client_platform !== null) {
                return; // ya lo fijó quien creó el registro
            }

            $request = app()->bound('request') ? app('request') : null;
            if (!$request instanceof Request || !$request->route()) {
                return; // no es un request HTTP (consola, cola, scripts)
            }

            [$platform, $version] = self::resolveClientOrigin($request);
            $model->client_platform = $platform;
            $model->client_app_version = $version;
        });
    }

    /**
     * - X-Client-Platform (ios|android|web): lo manda la app desde esta versión.
     * - Sin ese header pero con X-Client-Type: mobile → APK de Android anterior
     *   a este cambio (hasta ahora el único móvil en la calle).
     * - Sin ninguno de los dos → portal web.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function resolveClientOrigin(Request $request): array
    {
        $platform = strtolower(trim((string) $request->header('X-Client-Platform', '')));

        if (!in_array($platform, ['ios', 'android', 'web'], true)) {
            $platform = $request->header('X-Client-Type') === 'mobile' ? 'android' : 'web';
        }

        // La versión llega del teléfono: se acepta solo algo con forma de
        // versión ("2.0.3", "1.0-beta") para no guardar texto arbitrario.
        $version = trim((string) $request->header('X-App-Version', ''));
        if ($version === '' || !preg_match('/^[0-9A-Za-z.\-+]{1,20}$/', $version)) {
            $version = null;
        }

        return [$platform, $version];
    }
}
