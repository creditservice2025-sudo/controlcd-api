<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resincroniza el rol de Spatie de los usuarios cuyo `users.role_id` no está
 * reflejado en `model_has_roles`.
 *
 * Existe porque el alta nunca llamó a `assignRole()`: escribía la columna y
 * nada más. Esos usuarios entran a la aplicación con su rol a la vista pero sin
 * ningún permiso, y la interfaz les esconde todo lo que depende de uno —se ve
 * como una pantalla vacía, no como un error—. El observer UserRoleSyncObserver
 * evita que vuelva a pasar; esto arregla a los que ya están.
 *
 * Por defecto toca SOLO los roles parametrizables (Secretaria y los que se
 * creen de acá en adelante), el mismo alcance que el observer. Los roles fijos
 * quedan afuera a propósito: darles el rol de Spatie les CONCEDE los permisos
 * de su rol —a los 38 supervisores, 73 de una— y eso abre accesos en
 * producción. Si se decide hacerlo, se pide explícitamente con --rol=N, después
 * de revisar qué permisos tiene ese rol.
 *
 * Los roles 1 y 2 nunca entran: el Gate::before les concede todo sin mirar la
 * tabla, así que sincronizarlos no cambia nada y es tocar cuentas de más.
 *
 *   php artisan usuarios:sincronizar-roles              (simula, parametrizables)
 *   php artisan usuarios:sincronizar-roles --aplicar    (los aplica)
 *   php artisan usuarios:sincronizar-roles --rol=6      (simula los supervisores)
 */
class SyncUserRoles extends Command
{
    protected $signature = 'usuarios:sincronizar-roles
                            {--aplicar : Escribe los cambios. Sin esta opción solo informa.}
                            {--rol= : Un role_id concreto, incluso fijo (ej: --rol=6). Sin esto, solo los parametrizables.}';

    protected $description = 'Vincula a cada usuario con el rol de Spatie que dice su users.role_id';

    public function handle(): int
    {
        $roles = Role::where('guard_name', 'api')->get()->keyBy('id');

        $query = User::whereNull('deleted_at')
            ->whereNotIn('role_id', [1, 2])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', User::class);
            });

        if ($this->option('rol')) {
            $query->where('role_id', (int) $this->option('rol'));
        } else {
            // Sin --rol, el comando se limita a lo mismo que el observer: los
            // roles parametrizables. Meter a los fijos acá sería conceder
            // permisos a medio padrón sin que nadie lo haya pedido.
            $parametrizables = $roles->keys()
                ->filter(fn ($id) => \App\Support\Roles::esParametrizable($id))
                ->all();
            $query->whereIn('role_id', $parametrizables ?: [-1]);
        }

        $usuarios = $query->orderBy('role_id')->get(['id', 'name', 'role_id']);

        if ($usuarios->isEmpty()) {
            $this->info('No hay usuarios sin rol de Spatie. Nada que hacer.');
            return self::SUCCESS;
        }

        $aplicar = (bool) $this->option('aplicar');
        $this->line($usuarios->count() . ' usuario(s) sin rol de Spatie'
            . ($aplicar ? '' : ' (simulación: agregá --aplicar para escribir)') . ':');

        $porRol = [];
        $sinRolValido = [];

        foreach ($usuarios as $u) {
            $rol = $roles->get((int) $u->role_id);

            if (!$rol) {
                $sinRolValido[] = $u;
                continue;
            }

            $porRol[$rol->name] = ($porRol[$rol->name] ?? 0) + 1;

            if ($aplicar) {
                // syncRoles y no assignRole: el usuario tiene UN rol.
                User::find($u->id)?->syncRoles([$rol]);
            }
        }

        foreach ($porRol as $nombre => $cantidad) {
            $this->line(sprintf('  %-16s %d', $nombre, $cantidad));
        }

        if ($sinRolValido) {
            $this->warn('  ' . count($sinRolValido) . ' con un role_id que no existe en `roles` (se saltean):');
            foreach ($sinRolValido as $u) {
                $this->warn("    id={$u->id} role_id={$u->role_id} {$u->name}");
            }
        }

        if ($aplicar) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->info('Listo. Los usuarios ya tienen los permisos de su rol.');
        }

        return self::SUCCESS;
    }
}
