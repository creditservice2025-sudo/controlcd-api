<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Una opción del menú Consultas = un permiso.
 *
 * Hasta acá las cuatro primeras opciones colgaban todas de `consultar_reportes`
 * —o se abrían las cuatro, o ninguna—, y las otras dos ni siquiera eran
 * parametrizables: Morosos estaba cableado a los roles 1 y 2, y Auditoría de
 * Transferencias al rol 1. Para un rol de oficina eso significa que no hay
 * forma de darle "Clientes y ventas" sin darle también el mantenimiento de
 * crédito, ni de darle Morosos de ninguna manera.
 *
 * Auditoría de Transferencias NO entra en esta lista: ya tiene su permiso
 * propio (`ver_auditoria`) y su casilla en la pantalla; lo único que le faltaba
 * era que el menú lo consultara en vez de mirar el número de rol.
 *
 * Reparto de lo que ya existía:
 *  - Los cuatro que `consultar_reportes` abría se le dan a todo rol que hoy lo
 *    tenga: separar no puede quitarle a nadie lo que ya veía.
 *  - `consultar_morosos` NO se reparte: hoy no lo concede ningún permiso, así
 *    que copiarlo sería CONCEDER acceso nuevo a quien nunca lo tuvo. Los roles
 *    1 y 2 lo siguen viendo por su regla de siempre; el resto, cuando se lo
 *    enciendan desde "Módulos y Permisos".
 *
 * OJO: el permiso tiene que existir ANTES que su casilla —el guardado descarta
 * en silencio los nombres que no están en la tabla—, así que esta migración va
 * junto con el front que ya pregunta por ellos.
 */
return new class extends Migration
{
    /** Las que hoy abre `consultar_reportes`: se heredan. */
    private const HEREDAN = [
        'consultar_clientes_ventas',
        'consultar_mantenimiento_credito',
        'consultar_primer_cierre_rutas',
        'consultar_caja_en_linea',
    ];

    /** Hoy no la concede ningún permiso: nace apagada para todos. */
    private const NUEVAS = [
        'consultar_morosos',
    ];

    private const VIEJO = 'consultar_reportes';

    public function up(): void
    {
        $creados = [];
        foreach (array_merge(self::HEREDAN, self::NUEVAS) as $name) {
            $creados[$name] = Permission::firstOrCreate([
                'name'       => $name,
                'guard_name' => 'api',
            ]);
        }

        $viejo = Permission::where('guard_name', 'api')
            ->where('name', self::VIEJO)
            ->first();

        if ($viejo) {
            foreach (Role::where('guard_name', 'api')->get() as $role) {
                if (!$role->hasPermissionTo($viejo)) {
                    continue;
                }
                foreach (self::HEREDAN as $name) {
                    if (!$role->hasPermissionTo($creados[$name])) {
                        $role->givePermissionTo($creados[$name]);
                    }
                }
            }

            // Usuarios con el permiso asignado directo, si los hubiera. Entre
            // try/catch porque depende de que el guard 'api' tenga su modelo
            // resuelto: no vale reventar la migración por un caso marginal.
            try {
                foreach ($viejo->users as $user) {
                    foreach (self::HEREDAN as $name) {
                        if (!$user->hasDirectPermission($name)) {
                            $user->givePermissionTo($creados[$name]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'split_consultas_menu: no se pudieron copiar permisos directos de usuario: ' . $e->getMessage()
                );
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('guard_name', 'api')
            ->whereIn('name', array_merge(self::HEREDAN, self::NUEVAS))
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
