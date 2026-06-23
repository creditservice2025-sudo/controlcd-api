<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Agrega el permiso "ver_detalle_vendedor" para la vista individual del vendedor
 * (modal drill-down del dashboard + pagina /dashboard/sellers/:uuid).
 * Ademas lo asigna automaticamente a Super-Admin y Admin para que el middleware
 * no los bloquee cuando se active el enforcement.
 *
 * Idempotente via firstOrCreate + givePermissionTo (no duplica si ya lo tiene).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name'       => 'ver_detalle_vendedor',
            'guard_name' => 'api',
        ]);

        // Asignar a Super-Admin (1) y Admin (2) para evitar que se queden fuera.
        foreach (['Super-Admin', 'Admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            if ($role && !$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->where('name', 'ver_detalle_vendedor')
            ->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
