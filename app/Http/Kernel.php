<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * @var array
     */
    protected $middleware = [
        \Illuminate\Http\Middleware\HandleCors::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        // Bloquea las requests de Cobradores (rol 5) cuando su Supervisor
        // (rol 6) tiene una revisión activa. Aplicar SIEMPRE después de
        // auth:api para que el user esté resuelto.
        'supervisor.lock' => \App\Http\Middleware\CheckSupervisorLock::class,
        // Bloquea las requests del Cobrador (rol 5) tras cerrar su
        // liquidación. Paralelo a supervisor.lock pero independiente: usa
        // otra cache key y otro código de respuesta.
        'liquidation.closed' => \App\Http\Middleware\CheckLiquidationClosed::class,
        // Bloquea operaciones de escritura del Supervisor (rol 6) sobre un
        // vendedor cuya caja del día ya está cerrada. Aplicar después de
        // ResolveActiveSeller (active.seller) porque depende del atributo
        // active_seller_id.
        'block.writes.cash.closed' => \App\Http\Middleware\BlockSupervisorWritesOnClosedCash::class,
      /*   'permission' => \Spatie\Permission\Middlewares\PermissionMiddleware::class,
        'role' => \Spatie\Permission\Middlewares\RoleMiddleware::class,
        'permission_or' => \Spatie\Permission\Middlewares\PermissionOrMiddleware::class,
        'role_or' => \Spatie\Permission\Middlewares\RoleOrMiddleware::class, */
    ];
}
