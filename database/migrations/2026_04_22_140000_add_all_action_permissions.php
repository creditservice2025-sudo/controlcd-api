<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Catalogo completo de permisos de acciones para que el admin pueda restringir
 * o aprobar cualquier operacion desde la pantalla de Permisos.
 *
 * Incluye:
 *  - CRUD completo por pestana del detalle vendedor
 *  - Acciones especiales de negocio (aprobar, reversar, exportar, transferir, ajustar caja)
 *
 * Idempotente: solo crea permisos que no existen. Asigna a Super-Admin y Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'api';

        $permissions = [
            // CRUD por pestana del detalle vendedor
            'crear_vendedor_wallet',
            'editar_vendedor_wallet',
            'eliminar_vendedor_wallet',
            'crear_pagos',
            'editar_pagos',
            'eliminar_pagos',
            'crear_vendedor_ventas',
            'editar_vendedor_ventas',
            'eliminar_vendedor_ventas',
            'ver_vendedor_gps',          // ya existe, firstOrCreate es idempotente
            'editar_vendedor_gps',
            'ver_vendedor_configuracion',

            // Acciones especiales de negocio
            'aprobar_pagos',
            'reversar_pagos',
            'aprobar_liquidaciones',
            'rechazar_liquidaciones',
            'exportar_liquidaciones',
            'exportar_reportes',
            'exportar_pagos',
            'exportar_clientes',
            'transferir_clientes',
            'reactivar_clientes',
            'ajustar_caja',
            'mover_cartera_irrecuperable',
            'importar_clientes',
            'aprobar_imagenes',
        ];

        $created = [];
        foreach ($permissions as $name) {
            $created[] = Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        foreach (['Super-Admin', 'Admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();
            if (!$role) continue;
            foreach ($created as $perm) {
                if (!$role->hasPermissionTo($perm)) {
                    $role->givePermissionTo($perm);
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = 'api';

        $names = [
            'crear_vendedor_wallet', 'editar_vendedor_wallet', 'eliminar_vendedor_wallet',
            'crear_pagos', 'editar_pagos', 'eliminar_pagos',
            'crear_vendedor_ventas', 'editar_vendedor_ventas', 'eliminar_vendedor_ventas',
            'editar_vendedor_gps',
            'ver_vendedor_configuracion',
            'aprobar_pagos', 'reversar_pagos',
            'aprobar_liquidaciones', 'rechazar_liquidaciones',
            'exportar_liquidaciones', 'exportar_reportes',
            'exportar_pagos', 'exportar_clientes',
            'transferir_clientes', 'reactivar_clientes',
            'ajustar_caja', 'mover_cartera_irrecuperable',
            'importar_clientes', 'aprobar_imagenes',
        ];

        Permission::where('guard_name', $guard)->whereIn('name', $names)->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
