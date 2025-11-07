<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Ejemplo: El rol Super-Admin tiene todos los permisos
        $superAdmin = Role::where('name', 'Super-Admin')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::all());
        }

        // Ejemplo: El rol Admin tiene permisos de gestión
        $admin = Role::where('name', 'Admin')->first();
        if ($admin) {
            $admin->syncPermissions([
                // Ciudades
                'ver_ciudades', 'crear_ciudades', 'editar_ciudades', 'eliminar_ciudades',
                // Vendedores
                'ver_vendedores', 'crear_vendedores', 'editar_vendedores', 'eliminar_vendedores',
                // Roles y permisos
                'ver_roles', 'crear_roles', 'editar_roles', 'eliminar_roles',
                'ver_permisos', 'asignar_permisos',
                // Clientes
                'ver_clientes', 'crear_clientes', 'editar_clientes', 'eliminar_clientes',
                // Usuarios
                'ver_usuarios', 'crear_usuarios', 'editar_usuarios', 'eliminar_usuarios',
                // Liquidaciones
                'ver_liquidaciones', 'crear_liquidaciones', 'editar_liquidaciones', 'eliminar_liquidaciones',
                // Consultar
                'consultar_reportes', 'consultar_estadisticas',
                // Empresa
                'ver_empresa', 'editar_empresa',
                // Ingresos y egresos
                'ver_ingresos', 'crear_ingresos', 'editar_ingresos', 'eliminar_ingresos',
                'ver_egresos', 'crear_egresos', 'editar_egresos', 'eliminar_egresos',
            ]);
        }

    }
}
