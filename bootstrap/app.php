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
        ]);

        $middleware->validateCsrfTokens(except: [
            '*'
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('liquidation:auto-daily')->hourly();
        $schedule->command('liquidation:historical')->dailyAt('23:55');
        $schedule->command('liquidation:notify-pending')->dailyAt('21:52');
        $schedule->command('credits:notify-renewal-pending')->dailyAt('21:00');
        $schedule->command('credits:notify-new-credit-amount-limit')->dailyAt('21:05');
        $schedule->command('credits:notify-new-credit-limit')->dailyAt('21:10');
    })->create();
