<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Agrega `is_assignable` a la tabla `roles`: un interruptor de VISIBILIDAD para
 * el dropdown de "Nuevo usuario". NO reemplaza ni toca la lógica por role_id
 * (esa sigue intacta en services/middleware); solo controla qué roles se
 * OFRECEN al crear un miembro, SIN borrar ni renumerar ninguno.
 *
 * Los roles de sistema que nunca se asignan a mano desde la pantalla de
 * usuarios (Super-Admin, Admin, Cobrador y Cobrador-abono — estos se crean
 * por otros flujos) quedan en false. El resto en true. Para ocultar/mostrar
 * un rol a futuro basta togglear esta columna en BD: no hay que tocar el front.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('roles', 'is_assignable')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_assignable')->default(true);
            });
        }

        DB::table('roles')
            ->whereIn('name', ['Super-Admin', 'Admin', 'Cobrador', 'Cobrador-abono'])
            ->update(['is_assignable' => false]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'is_assignable')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('is_assignable');
            });
        }
    }
};
