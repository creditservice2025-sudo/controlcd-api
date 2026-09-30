<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Permisos que la interfaz ya consultaba pero que NUNCA existieron en la base,
 * mas los del tablero, que no tenia ningun candado.
 *
 * El menu preguntaba por `ver_resumen_liquidaciones`, `ver_rutas_activas`,
 * `ver_parametros`, `ver_administracion`, `realizar_carga_masiva` y `enrutar`.
 * Como el permiso no existia, la respuesta era siempre "no" para todo rol que no
 * fuera 1 o 2, y por eso cada una de esas opciones estaba cableada con
 * `role === 11`: era el parche para que la Secretaria viera algo que el permiso
 * no podia concederle. Al quitar el cableado hay que crear el permiso, o la
 * opcion queda fuera del alcance de cualquier rol parametrizable.
 *
 * OJO con el orden: `RolePermissionController::assignPermissions` filtra por
 * `Permission::whereIn('name', ...)` antes de sincronizar. Un permiso que no
 * existe se descarta EN SILENCIO —la pantalla avisa "guardado correctamente" y
 * no pasa nada—, asi que el permiso tiene que nacer antes que su casilla.
 *
 * No se asignan a ningun rol: que cada empresa decida desde la pantalla de
 * Modulos y Permisos. Asignarlos por defecto es volver al problema de origen.
 */
return new class extends Migration
{
    /** Permisos que el menu ya consultaba sin que existieran. */
    private const PERMISOS_MENU = [
        'ver_resumen_liquidaciones',
        'ver_rutas_activas',
        'ver_parametros',
        'ver_administracion',
        'realizar_carga_masiva',
        'enrutar',
    ];

    /**
     * Tablero. Se parte en cuatro y no en uno solo porque son decisiones
     * distintas: el balance de la empresa es dato sensible, los contadores no.
     */
    private const PERMISOS_TABLERO = [
        'ver_caja',                // Tarjeta "Efectivo caja" (caja del dia y balance general)
        'ver_movimientos',         // Seccion "Movimientos"
        'ver_indicadores',         // Contadores de rutas / miembros / clientes / creditos
        'ver_carteras_pendientes', // Bloque "Ultimas carteras pendientes"
    ];

    public function up(): void
    {
        $guard = 'api';

        foreach (array_merge(self::PERMISOS_MENU, self::PERMISOS_TABLERO) as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => $guard,
            ]);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', array_merge(self::PERMISOS_MENU, self::PERMISOS_TABLERO))
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
