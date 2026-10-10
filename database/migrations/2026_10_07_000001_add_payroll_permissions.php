<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Permisos del módulo de Nómina semanal.
 *
 * Tienen que existir ANTES que sus casillas en Módulos y Permisos:
 * RolePermissionController::assignPermissions descarta en silencio los nombres
 * que no existen. No se asignan a ningún rol: cada empresa decide. Los roles 1
 * y 2 pasan igual por el Gate::before.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'ver_nomina',         // Ver nóminas y su detalle
        'crear_nomina',       // Generar, recalcular y ajustar una nómina en borrador
        'configurar_nomina',  // Reglas de comisión y día de inicio de semana
        'aprobar_nomina',     // Aprobar / anular
        'pagar_nomina',       // Pagar (crea el gasto en la caja del cobrador)
        'exportar_nomina',    // PDF / Excel
    ];

    public function up(): void
    {
        foreach (self::PERMISOS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISOS)->where('guard_name', 'api')->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
