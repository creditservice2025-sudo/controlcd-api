<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombra los roles "Revisador" → "Supervisor" y "Consultor" → "Secretaria"
 * para alinear los nombres con cómo se les llama en el negocio. El role_id
 * NO cambia (sigue siendo 6 para Supervisor y 11 para Secretaria); solo
 * cambia el `name`. Todo el código que compara por role_id sigue igual;
 * solo los filtros/comentarios que comparaban por string se actualizan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'Revisador')
            ->where('guard_name', 'api')
            ->update(['name' => 'Supervisor', 'updated_at' => now()]);

        DB::table('roles')
            ->where('name', 'Consultor')
            ->where('guard_name', 'api')
            ->update(['name' => 'Secretaria', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'Supervisor')
            ->where('guard_name', 'api')
            ->update(['name' => 'Revisador', 'updated_at' => now()]);

        DB::table('roles')
            ->where('name', 'Secretaria')
            ->where('guard_name', 'api')
            ->update(['name' => 'Consultor', 'updated_at' => now()]);
    }
};
