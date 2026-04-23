<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        Passport::tokensExpireIn(now()->addMinutes(90));
        Passport::refreshTokensExpireIn(now()->addMinutes(90));
        Passport::personalAccessTokensExpireIn(now()->addMinutes(90));

        // Bypass de permisos para Super-Admin y Admin.
        // Cualquier middleware permission:xxx y cualquier $user->can() retorna true para estos roles,
        // sin importar si el permiso especifico esta asignado en role_has_permissions.
        // Patron estandar Spatie Laravel Permission para super-admin:
        // https://spatie.be/docs/laravel-permission/v6/basic-usage/super-admin
        Gate::before(function ($user, $ability) {
            if (!$user) return null;
            // Preferir role_id (numerico) porque es mas rapido y robusto
            if (isset($user->role_id) && in_array($user->role_id, [1, 2], true)) {
                return true;
            }
            // Fallback a roles Spatie por nombre
            try {
                if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['Super-Admin', 'Admin'])) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignorar si el user no tiene el trait HasRoles
            }
            return null; // null = no decide, deja que continue el flujo normal
        });
    }
}
