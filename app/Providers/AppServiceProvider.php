<?php

namespace App\Providers;

use App\Models\SellerConfig;
use App\Observers\SellerConfigObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Auditoría automática de cambios en configuración de seguridad por
        // vendedor: registra usuario, timestamp y diff de campos modificados
        // en seller_config_audits.
        SellerConfig::observe(SellerConfigObserver::class);

        // Bypass de permisos para Super-Admin y Admin.
        // Cualquier middleware permission:xxx y cualquier $user->can() retorna true para estos roles,
        // sin importar si el permiso especifico esta asignado en role_has_permissions.
        // Patron estandar Spatie Laravel Permission para super-admin:
        // https://spatie.be/docs/laravel-permission/v6/basic-usage/super-admin
        // Rate limit del envío Telegram (Telegram limita ~30 msgs/seg/bot).
        // Dejamos margen y permitimos 20 por segundo. Si se supera, el job
        // se reencola automáticamente gracias al middleware RateLimited.
        RateLimiter::for('telegram-notifications', function () {
            return Limit::perSecond(20);
        });

        // Límite de intentos de login: 10 por minuto por (usuario + IP).
        // Antes era throttle:6,1 (solo por IP), que en redes compartidas
        // (oficina / NAT) hacía que un usuario bloqueara a TODOS los que
        // salen por la misma IP, y 6 era muy justo para tipeos. Keyear por
        // usuario+IP es la práctica correcta: mantiene la protección
        // anti-fuerza-bruta por cuenta sin castigar a terceros.
        RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $identifier = strtolower(trim((string) (
                $request->input('email')
                    ?: $request->input('username')
                    ?: $request->input('dni')
                    ?: ''
            )));
            return Limit::perMinute(10)->by($identifier . '|' . $request->ip());
        });

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
