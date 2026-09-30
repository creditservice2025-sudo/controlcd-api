<?php

namespace App\Observers;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mantiene el rol de Spatie en sincronía con `users.role_id`.
 *
 * El sistema guarda el rol en DOS lugares: la columna `users.role_id` y la
 * tabla `model_has_roles` de Spatie. La pantalla de alta solo escribía la
 * columna —`assignRole()` no aparecía en ningún lado de `app/`—, así que el
 * usuario quedaba con su rol a la vista pero SIN los permisos del rol: `/me`
 * devolvía una lista vacía y la interfaz le escondía todo lo que depende de un
 * permiso. Se veía como una pantalla sin pestañas ni opciones, no como un error.
 *
 * Los roles 1 y 2 no lo notaban porque el `Gate::before` de AppServiceProvider
 * les concede todo sin mirar la tabla. Por eso el problema apareció recién
 * cuando los roles parametrizables (Supervisor, Secretaria) empezaron a
 * gobernarse por permisos.
 *
 * Va como observer y no dentro de UserService para que valga en TODOS los
 * caminos —alta desde la pantalla, seeders, importaciones, comandos— y no solo
 * en el que se arregló.
 *
 * ALCANCE: SOLO roles parametrizables (Secretaria y los que se creen de acá en
 * adelante). Los roles fijos 1 a 10 quedan afuera A PROPÓSITO, no por olvido:
 * sincronizarlos les daría los permisos de su rol, que hoy NO tienen —a los 38
 * supervisores, 73 de golpe—, y eso ABRE accesos en producción con cobradores
 * trabajando. Ese cambio ya se intentó antes y se revirtió por eso mismo; es
 * una decisión del negocio, no una que se arregle de paso. Para hacerlo a
 * conciencia está `php artisan usuarios:sincronizar-roles --rol=N`.
 *
 * Los roles parametrizables sí entran porque SIN esto no funcionan: su alcance
 * entero se define por permisos, así que un usuario sin el vínculo de Spatie
 * entra a una aplicación vacía.
 *
 * Solo actúa cuando `role_id` cambió: en un `save()` cualquiera (por ejemplo
 * el `updated_by` de una edición) no toca nada.
 */
class UserRoleSyncObserver
{
    public function created(User $user): void
    {
        $this->sincronizar($user);
    }

    public function updated(User $user): void
    {
        // wasChanged() y no isDirty(): en `updated` los cambios ya se
        // guardaron, así que isDirty() ya vuelve falso.
        if ($user->wasChanged('role_id')) {
            $this->sincronizar($user);
        }
    }

    private function sincronizar(User $user): void
    {
        try {
            if (empty($user->role_id) || !Roles::esParametrizable($user->role_id)) {
                return;
            }

            $rol = Role::where('guard_name', 'api')
                ->where('id', $user->role_id)
                ->first();

            if (!$rol) {
                Log::warning("UserRoleSyncObserver: users.role_id={$user->role_id} no existe en roles (usuario {$user->id}).");
                return;
            }

            // syncRoles y no assignRole: un usuario tiene UN rol, y al cambiarlo
            // hay que sacarle el anterior. assignRole los iría acumulando y el
            // usuario terminaría con los permisos de los dos.
            $user->syncRoles([$rol]);

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable $e) {
            // Nunca tumbar el alta por esto: es mejor un usuario creado al que
            // haya que resincronizar que un alta que falla. Queda en el log.
            Log::error('UserRoleSyncObserver: no se pudo sincronizar el rol del usuario '
                . $user->id . ': ' . $e->getMessage());
        }
    }
}
