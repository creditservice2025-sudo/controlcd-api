<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Separa el resumen de CARTERA del resumen de LIQUIDACIONES.
 *
 * Hasta acá las dos pantallas colgaban del mismo permiso
 * (`ver_resumen_liquidaciones`), con el argumento de que era "el mismo dato
 * visto de otra forma". No lo es: el resumen de liquidaciones es el recaudo del
 * período —lo que pasó por caja— y la cartera es el saldo vivo —lo que se debe
 * hoy—. Hay roles de oficina que deben ver el movimiento del día sin conocer el
 * tamaño de la cartera, así que cada pantalla necesita su propio interruptor.
 *
 * El permiso NUEVO se le da a todo rol que hoy tenga el viejo: separarlos no
 * puede quitarle a nadie lo que ya veía. Quien ya no deba ver la cartera se le
 * apaga después desde "Módulos y Permisos", que es donde se decide.
 *
 * OJO: el permiso tiene que existir ANTES que su casilla en la pantalla —el
 * guardado descarta en silencio los nombres que no están en la tabla—, y esta
 * migración tiene que correr junto con el front que ya pregunta por él.
 */
return new class extends Migration
{
    private const NUEVO = 'ver_resumen_cartera';
    private const VIEJO = 'ver_resumen_liquidaciones';

    public function up(): void
    {
        $nuevo = Permission::firstOrCreate([
            'name'       => self::NUEVO,
            'guard_name' => 'api',
        ]);

        $viejo = Permission::where('guard_name', 'api')
            ->where('name', self::VIEJO)
            ->first();

        if ($viejo) {
            // Roles que ya veían la cartera con el permiso compartido.
            foreach (Role::where('guard_name', 'api')->get() as $role) {
                if ($role->hasPermissionTo($viejo) && !$role->hasPermissionTo($nuevo)) {
                    $role->givePermissionTo($nuevo);
                }
            }

            // Y los usuarios con el permiso asignado directo, si los hubiera:
            // son pocos, pero quedarían sin cartera sin que nadie lo note.
            // Entre try/catch porque el permiso por usuario depende de que el
            // guard 'api' tenga su modelo resuelto: si no, esto reventaría la
            // migración entera por un caso que casi no se usa.
            try {
                foreach ($viejo->users as $user) {
                    if (!$user->hasDirectPermission(self::NUEVO)) {
                        $user->givePermissionTo($nuevo);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'split_resumen_cartera: no se pudieron copiar permisos directos de usuario: ' . $e->getMessage()
                );
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->where('name', self::NUEVO)
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
