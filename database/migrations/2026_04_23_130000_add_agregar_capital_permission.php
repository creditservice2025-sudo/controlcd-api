<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Permiso granular: agregar capital a un credito Collection existente.
 * Super-Admin y Admin lo pasan automaticamente via Gate::before en AppServiceProvider,
 * asi que no hace falta asignarlo aqui. Si se quiere delegar a otro rol, se configura
 * desde la pantalla de Permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate([
            'name' => 'agregar_capital_collection',
            'guard_name' => 'api',
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->where('name', 'agregar_capital_collection')
            ->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
