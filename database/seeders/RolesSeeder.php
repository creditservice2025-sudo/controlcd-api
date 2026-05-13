<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Roles de Control CD — el ORDEN ES IMPORTANTE: define los IDs
        // numéricos (Spatie asigna 1, 2, 3... según se crean). NO reordenar
        // ni borrar — código en services/middleware compara por role_id.
        //
        //  ID  Nombre          Web  APK  Notas
        // ---  --------------  ---  ---  -----------------------------------
        //   1  Super-Admin     ✓    ✗    Acceso total al sistema
        //   2  Admin           ✓    ✗    Admin de empresa
        //   3  Socio           ✓    ✗    Socio inversor
        //   4  Asistente       ✓    ✗    Operativo administrativo
        //   5  Cobrador        ✓    ✓    Vendedor de campo. Su sesión queda
        //                                 bloqueada (en web Y APK) mientras
        //                                 su Supervisor (rol 6) esté logueado.
        //   6  Revisador       ✓    ✓    Supervisor de cobradores. Al iniciar
        //                                 sesión revoca tokens de sus
        //                                 cobradores asignados (user_routes).
        //   7  Cobrador-abono  ✓    ✗    Cobrador limitado a abonos
        //   8  Limitado        ✓    ✗    Acceso restringido
        //   9  Digitador       ✓    ✗    Solo carga de datos
        //  10  Contador        ✓    ✗    Solo lectura financiera
        //  11  Consultor       ✓    ✗    Solo consulta
        $roles = [
            'Super-Admin',
            'Admin',
            'Socio',
            'Asistente',
            'Cobrador',
            'Revisador',

            'Cobrador-abono',
            'Limitado',
            'Digitador',
            'Contador',
            'Consultor',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        }
    }
}
