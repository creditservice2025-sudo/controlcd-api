<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\SanitizeFileUploads::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            // Aliases Spatie Permission (Laravel 11 requiere registro explicito).
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Bloquea Cobrador (rol 5) cuando su Supervisor (rol 6) tiene
            // sesión activa. Aplicado al grupo `auth:api` en routes/api.php.
            'supervisor.lock'    => \App\Http\Middleware\CheckSupervisorLock::class,
            // Bloquea Cobrador (rol 5) tras cerrar su liquidación: cualquier
            // dispositivo del mismo user recibe 401 hasta nuevo login.
            // Paralelo a supervisor.lock pero independiente.
            'liquidation.closed' => \App\Http\Middleware\CheckLiquidationClosed::class,
            // Resuelve el seller "activo" para el Supervisor (rol 6) cuando
            // selecciona una ruta a supervisar desde el APK. Lee el header
            // X-Active-Seller-Id y lo expone como $request->active_seller_id.
            'active.seller'      => \App\Http\Middleware\ResolveActiveSeller::class,
            // Modo solo-lectura para Supervisor (rol 6) cuando supervisa a
            // un vendedor con caja cerrada. Debe ir después de active.seller
            // porque depende del atributo active_seller_id.
            'block.writes.cash.closed' => \App\Http\Middleware\BlockSupervisorWritesOnClosedCash::class,
            // El Cobrador (rol 5) solo puede operar desde el APK móvil. Si
            // llega desde el web (sin X-Client-Type: mobile) devuelve 401 para
            // cerrar la sesión del navegador. Corre después de auth:api.
            'seller.apk.only'    => \App\Http\Middleware\BlockSellerWebSession::class,
            // Restringe el ingreso del Cobrador (rol 5) en días no laborables de
            // su ruta (hoy: domingo, según seller_configs.works_sundays). Expulsa
            // también sesiones abiertas de un día anterior. Corre después de auth:api.
            'seller.workingday'  => \App\Http\Middleware\CheckSellerWorkingDay::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '*'
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })
    ->withSchedule(function (Schedule $schedule) {
        // auto-daily cierra SOLO el dia en curso (hoy), en la ventana 23:55-23:59
        // de la zona horaria del pais de cada vendedor. Se quito la ventana
        // 00:00-00:29 que cerraba AYER: el cierre es del mismo dia, nunca
        // retroactivo al dia siguiente. everyMinute -> la ventana 23:55-23:59 se
        // dispara 5 veces (reintentos same-day) sin cruzar medianoche.
        // withoutOverlapping evita solapes (seguro ademas por el indice unico +
        // getOrCreate atomico).
        $schedule->command('liquidation:auto-daily')
            ->everyMinute()
            ->withoutOverlapping()
            // (A) Si el comando revienta (excepcion / exit != 0), avisa por correo.
            ->emailOutputOnFailure('creditservice2025@gmail.com');

        // (B) Red de seguridad: un rato despues de la medianoche de todos los
        // paises (07:00 UTC cubre hasta UTC-6), verifica que el auto-cierre haya
        // cerrado los dias de los cobradores y NOTIFICA por correo los que
        // quedaron 'En curso'. No cierra nada, solo avisa.
        $schedule->command('liquidation:check-auto-closures')
            ->timezone('UTC')->dailyAt('07:00')->withoutOverlapping();

        $schedule->command('liquidation:historical')->dailyAt('23:55');
        $schedule->command('liquidation:notify-pending')->dailyAt('21:52');
        $schedule->command('credits:notify-renewal-pending')->dailyAt('21:00');
        $schedule->command('credits:notify-new-credit-amount-limit')->dailyAt('21:05');
        $schedule->command('credits:notify-new-credit-limit')->dailyAt('21:10');

        // Collection (Deuda & Abono): corte de caja automático. everyMinute para
        // clavar las 23:59 en la zona horaria local de CADA empresa (los países
        // difieren) y para recuperar días previos que se quedaron sin cierre.
        $schedule->command('collection:check-pending-closures')
            ->everyMinute()
            ->withoutOverlapping()
            ->emailOutputOnFailure('creditservice2025@gmail.com');
    })->create();
