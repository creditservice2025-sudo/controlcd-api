<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de CREACIÓN para clientes y créditos: quién los creó y con qué
 * rol. Sigue la convención ya usada en `liquidations` (closed_by /
 * closed_by_role).
 *
 *  - created_by      → user_id que creó el registro (Auth::id()).
 *  - created_by_role → SNAPSHOT del rol al momento de crear (role_id). Se
 *    guarda el rol del momento porque los roles cambian con el tiempo; así
 *    sabemos si lo creó cobrador (5), supervisor (6), admin (1/2), etc.,
 *    aunque ese usuario cambie de rol después.
 *
 * Notas DevOps:
 *  - Columnas NULLABLE → en MySQL 8 el ADD COLUMN es ALGORITHM=INSTANT
 *    (metadata, sin lock) aun con 32k clients / 90k credits.
 *  - SIN foreign key duro (evita validación + lock); integridad por la capa
 *    de aplicación (Auth::id()). Índice en created_by para reportes.
 *  - Idempotente.
 *  - Filas viejas quedan en NULL (no hay registro histórico del creador);
 *    exacto de aquí en adelante.
 *
 * ⚠️ `credits` es core del módulo financing. Aditivo y de bajo riesgo, pero
 * desplegar coordinado y probar alta de cliente/crédito en staging.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['clients', 'credits'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'created_by')) {
                    $t->unsignedBigInteger('created_by')->nullable();
                    $t->index('created_by', "{$table}_created_by_index");
                }
                if (!Schema::hasColumn($table, 'created_by_role')) {
                    $t->unsignedTinyInteger('created_by_role')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['clients', 'credits'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'created_by')) {
                    $t->dropIndex("{$table}_created_by_index");
                    $t->dropColumn('created_by');
                }
                if (Schema::hasColumn($table, 'created_by_role')) {
                    $t->dropColumn('created_by_role');
                }
            });
        }
    }
};
