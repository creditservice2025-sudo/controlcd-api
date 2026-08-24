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
            // Cobrar y colocar es trabajo de campo: el Super-Admin (1) y el
            // Admin de empresa (2) no registran pagos ni colocan/renuevan
            // créditos. Se aplica solo a los tres endpoints de alta, desde el
            // constructor de PaymentController y CreditController.
            'block.admin.field.ops' => \App\Http\Middleware\BlockAdminFieldOperations::class,
            // Restringe el ingreso del Cobrador (rol 5) en días no laborables de
            // su ruta (hoy: domingo, según seller_configs.works_sundays). Expulsa
            // también sesiones abiertas de un día anterior. Corre después de auth:api.
            'seller.workingday'  => \App\Http\Middleware\CheckSellerWorkingDay::class,
            // Permisos granulares del módulo Collection (Deuda & Abono), leídos
            // de collection_user_profiles.permissions. Fail-safe: admin pasa,
            // sin perfil permite, solo deniega a perfiles que carecen del permiso.
            'collection.permission' => \App\Http\Middleware\EnsureCollectionPermission::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '*'
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Las reglas de integridad de la caja son negocio, no fallo técnico:
        // se devuelven como 422 con el motivo legible. Si cayeran en el
        // handler genérico saldrían como 500 "Error al procesar la operación"
        // y el usuario no se enteraría de POR QUÉ se rechazó —que es
        // justamente lo que hay que evitar en un descuadre.
        $exceptions->render(function (\App\Exceptions\LiquidationIntegrityException $e, $request) {
            \Illuminate\Support\Facades\Log::warning('[liquidation.integrity] operación rechazada', [
                'motivo'  => $e->getMessage(),
                'user_id' => optional($request->user())->id,
                'ruta'    => $request->path(),
            ]);

            return response()->json([
                'success'    => false,
                'message'    => $e->getMessage(),
                'error_code' => 'LIQUIDATION_INTEGRITY',
            ], 422);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // auto-daily cierra SOLO el dia en curso (hoy), en la ventana 23:55-23:59
        // de la zona horaria del pais de cada vendedor. Se quito la ventana
        // 00:00-00:29 que cerraba AYER: el cierre es del mismo dia, nunca
        // retroactivo al dia siguiente. everyMinute -> la ventana 23:55-23:59 se
        // dispara 5 veces (reintentos same-day) sin cruzar medianoche.
        // withoutOverlapping evita solapes (seguro ademas por el indice unico +
        // getOrCreate atomico).
        //
        // --no-sweep: el cron cierra SOLO EL DÍA EN CURSO. El barrido de días
        // anteriores (sweepStaleOpenDays) queda APAGADO en la programación a
        // propósito.
        //
        // Motivo: cerrar un día viejo le recalcula los montos con los datos de
        // hoy y NO recalcula los días que vienen detrás, así que el
        // `initial_cash` del día siguiente queda desalineado con el
        // `real_to_deliver` que se acaba de reescribir. Medido antes de este
        // cambio: 215 días abiertos con fecha pasada, 128 vendedores, y 96 de
        // ellos con días posteriores — se habrían cerrado y recalculado solos
        // en ~3 minutos desde el primer schedule:run, descuadrando la cadena.
        //
        // El barrido sigue disponible como HERRAMIENTA MANUAL, para atacar ese
        // histórico en ventana controlada y verificando la cadena antes y
        // después:
        //     php artisan liquidations:verify-chain          (antes)
        //     php artisan liquidation:auto-daily --sweep-limit=10
        //     php artisan liquidations:verify-chain          (después)
        //
        // Subir el código y reparar el histórico son dos operaciones distintas:
        // un despliegue no debe mover una caja pasada.
        $schedule->command('liquidation:auto-daily --no-sweep')
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

        // Completa las direcciones de geolocalizacion que no se pudieron
        // resolver al momento de guardarlas (proveedor caido, timeout). La
        // coordenada ya esta guardada; esto solo le pone nombre. Va cada 5
        // minutos y de a pocos registros para respetar el limite de 1
        // consulta/segundo de Nominatim.
        $schedule->command('geo:fill-addresses --limit=30')
            ->everyFiveMinutes()
            ->withoutOverlapping();

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
