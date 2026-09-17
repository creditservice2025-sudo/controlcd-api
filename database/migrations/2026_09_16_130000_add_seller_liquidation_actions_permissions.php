<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Permisos propios de la pestaña "Liquidaciones" del detalle del vendedor
 * (/dashboard/sellers/:uuid), para decidir desde "Módulos y Permisos" qué
 * puede hacer un rol parametrizable con cada liquidación:
 *
 *  - descargar_liquidaciones_vendedor     → menú ⋮: Descargar PDF / Excel
 *  - cerrar_liquidaciones_vendedor        → menú ⋮: Cerrar liquidación
 *  - cierre_masivo_liquidaciones_vendedor → botón "Cierre masivo"
 *
 * Son permisos de PANTALLA: deciden qué se muestra. Cerrar sigue exigiendo
 * además `aprobar_liquidaciones`, que es lo que valida la API
 * (`permission:aprobar_liquidaciones`) y la pantalla de cierre; por eso la
 * interfaz pide los dos antes de ofrecer el botón.
 *
 * No se asignan a ningún rol: los roles 1 y 2 ven todo por diseño y los demás
 * roles no se rigen por estos permisos. Se habilitan desde la pantalla.
 *
 * El permiso tiene que existir ANTES que su casilla: el guardado de permisos
 * descarta en silencio los nombres que no están en la tabla.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'descargar_liquidaciones_vendedor',
        'cerrar_liquidaciones_vendedor',
        'cierre_masivo_liquidaciones_vendedor',
    ];

    public function up(): void
    {
        foreach (self::PERMISOS as $name) {
            Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => 'api',
            ]);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', self::PERMISOS)
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
