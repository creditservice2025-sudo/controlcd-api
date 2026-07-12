<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametriza por vendedor si su ruta trabaja los DOMINGOS.
 *
 * works_sundays = true  (default): opera normal todos los días.
 * works_sundays = false: al Cobrador (rol 5) se le RESTRINGE el ingreso a la
 * app los domingos (en la zona horaria del vendedor) — bloqueo en el login y
 * expulsión de sesiones activas vía middleware.
 *
 * Default true para NO cambiar el comportamiento de ningún vendedor existente:
 * solo se restringe a quien se marque explícitamente en false.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente/reanudable: seguro para el deploy de producción.
        if (!Schema::hasColumn('seller_configs', 'works_sundays')) {
            Schema::table('seller_configs', function (Blueprint $table) {
                $table->boolean('works_sundays')->default(true)->after('auto_closures_collectors');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seller_configs', 'works_sundays')) {
            Schema::table('seller_configs', function (Blueprint $table) {
                $table->dropColumn('works_sundays');
            });
        }
    }
};
