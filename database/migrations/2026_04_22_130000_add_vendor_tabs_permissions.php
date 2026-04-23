<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Permisos por pestana de la pagina "Detalle Vendedor" (/dashboard/sellers/:uuid).
 * Granulares: cada tab se puede activar/desactivar por rol desde la pantalla
 * "Configuracion de Modulos y Permisos CRUD".
 *
 * Las tabs que ya tenian permiso equivalente se reusan (ver_clientes, ver_liquidaciones,
 * ver_rutas, ver_egresos, ver_ingresos, consultar_reportes). Solo creamos aqui los
 * 5 que no existian.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'api';

        $newPermissions = [
            'ver_vendedor_wallet',
            'ver_pagos',
            'ver_vendedor_ventas',
            'ver_vendedor_gps',
            'editar_vendedor_configuracion',
        ];

        $created = [];
        foreach ($newPermissions as $name) {
            $created[] = Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        // NO asignamos estos permisos a Super-Admin ni Admin por default:
        // son menus/acciones operativas del detalle vendedor (Wallet/Pagos/Ventas/GPS/Config)
        // que solo deben aparecer para roles que el admin decida habilitar explicitamente.
        // Asignarlos automaticamente hace que los Admin vean menus de cobrador que no usan.

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = 'api';

        $names = [
            'ver_vendedor_wallet',
            'ver_pagos',
            'ver_vendedor_ventas',
            'ver_vendedor_gps',
            'editar_vendedor_configuracion',
        ];

        Permission::where('guard_name', $guard)->whereIn('name', $names)->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
