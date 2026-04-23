<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Restaura los permisos funcionales para los roles de Cobrador (Vendedor)
     * que fueron restringidos accidentalmente en las migraciones de ayer.
     */
    public function up(): void
    {
        $guard = 'api';
        
        $rolesToUpdate = ['Cobrador', 'Cobrador-abono'];
        $permissionsToAssign = ['crear_pagos', 'ver_detalle_vendedor'];

        foreach ($rolesToUpdate as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();
            
            if ($role) {
                foreach ($permissionsToAssign as $permName) {
                    $permission = Permission::where('name', $permName)->where('guard_name', $guard)->first();
                    
                    if ($permission && !$role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                    }
                }
            }
        }

        // Limpiar cache de permisos
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $guard = 'api';
        $rolesToUpdate = ['Cobrador', 'Cobrador-abono'];
        $permissionsToRemove = ['crear_pagos', 'ver_detalle_vendedor'];

        foreach ($rolesToUpdate as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();
            
            if ($role) {
                foreach ($permissionsToRemove as $permName) {
                    $permission = Permission::where('name', $permName)->where('guard_name', $guard)->first();
                    
                    if ($permission && $role->hasPermissionTo($permission)) {
                        $role->revokePermissionTo($permission);
                    }
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
