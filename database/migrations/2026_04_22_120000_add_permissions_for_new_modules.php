<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Registra los permisos de los modulos nuevos agregados en la pantalla
 * "Configuracion de Modulos y Permisos CRUD":
 *  - Creditos, Cobranzas, Rutas, Renovaciones
 *  - Auditoria, Notificaciones, Ciudades
 *  - Importaciones, Aprobaciones, Sistema, Dashboard
 *
 * Idempotente: usa firstOrCreate y se puede correr varias veces sin duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El sistema usa guard 'api' (Passport/Sanctum) para los permisos Spatie.
        $guard = 'api';

        // [modulo_snake => ['create','read','update','delete']]
        $map = [
            'creditos'           => ['create', 'read', 'update', 'delete'],
            'cobranzas'          => ['create', 'read', 'update', 'delete'],
            'rutas'              => ['create', 'read', 'update', 'delete'],
            'renovaciones'       => ['create', 'read', 'update', 'delete'],
            'notificaciones'     => ['create', 'read', 'update', 'delete'],
            'ciudades'           => ['create', 'read', 'update', 'delete'],
            'auditoria'          => ['read'],
            'importaciones'      => ['create', 'read'],
            'aprobaciones'       => ['read', 'update'],
            'sistema'            => ['read', 'update'],
            'dashboard'          => ['read'],
            'detalle_vendedor'   => ['read'],
        ];

        $prefixes = [
            'create' => 'crear_',
            'read'   => 'ver_',
            'update' => 'editar_',
            'delete' => 'eliminar_',
        ];

        foreach ($map as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name'       => $prefixes[$action] . $module,
                    'guard_name' => $guard,
                ]);
            }
        }

        // Limpia el cache de permisos de Spatie para que queden disponibles inmediatamente
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = 'api';

        $names = [
            'crear_creditos', 'ver_creditos', 'editar_creditos', 'eliminar_creditos',
            'crear_cobranzas', 'ver_cobranzas', 'editar_cobranzas', 'eliminar_cobranzas',
            'crear_rutas', 'ver_rutas', 'editar_rutas', 'eliminar_rutas',
            'crear_renovaciones', 'ver_renovaciones', 'editar_renovaciones', 'eliminar_renovaciones',
            'crear_notificaciones', 'ver_notificaciones', 'editar_notificaciones', 'eliminar_notificaciones',
            'crear_ciudades', 'ver_ciudades', 'editar_ciudades', 'eliminar_ciudades',
            'ver_auditoria',
            'crear_importaciones', 'ver_importaciones',
            'ver_aprobaciones', 'editar_aprobaciones',
            'ver_sistema', 'editar_sistema',
            'ver_dashboard',
            'ver_detalle_vendedor',
        ];

        Permission::where('guard_name', $guard)->whereIn('name', $names)->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
