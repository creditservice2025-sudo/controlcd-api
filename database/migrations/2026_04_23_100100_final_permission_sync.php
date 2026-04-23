<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Esta migración fuerza la sincronización de los roles de Spatie en Producción.
     * Es necesaria porque la migración anterior pudo haberse ejecutado antes de 
     * incluir la lógica de sincronización de usuarios.
     */
    public function up(): void
    {
        // 1. Asegurar que los roles tengan los permisos correctos
        $guard = 'api';
        $rolesToUpdate = ['Cobrador', 'Cobrador-abono'];
        $permissions = ['crear_pagos', 'ver_detalle_vendedor'];

        foreach ($rolesToUpdate as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        // 2. Sincronizar todos los usuarios con sus roles de Spatie
        $users = User::whereNotNull('role_id')->get();
        foreach ($users as $user) {
            $role = Role::find($user->role_id);
            if ($role && !$user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        // 3. Limpiar cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No destructivo
    }
};
