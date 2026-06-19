<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad del pago: quién lo registró, quién lo eliminó y dónde se
 * registró (dirección textual; las coordenadas GPS ya existen en
 * latitude/longitude).
 *
 * Diseño (ver análisis): un pago se crea una vez y se elimina a lo sumo una
 * vez, así que son atributos de un solo valor → columnas en `payments`, no
 * una tabla de auditoría aparte.
 *
 * Notas DevOps:
 *  - Columnas NULLABLE → en MySQL 8 el ADD COLUMN es ALGORITHM=INSTANT
 *    (metadata, sin reconstruir la tabla, sin lock) aun con ~600k filas.
 *  - SIN foreign key duro a propósito: el FK requeriría validación + lock.
 *    Se usan unsignedBigInteger + índice. (La integridad se garantiza en la
 *    capa de aplicación: created_by/deleted_by = Auth::id().)
 *  - `created_by` reemplaza al `user_id` que el código ya pasaba pero se
 *    descartaba (no existía la columna).
 *  - Idempotente: no recrea columnas/índices existentes.
 *
 * ⚠️ Toca la tabla `payments` (core financing). Aditivo y de bajo riesgo,
 * pero desplegar coordinado y probar registrar/eliminar pago en staging.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'created_by')) {
                // Quién registró el pago.
                $table->unsignedBigInteger('created_by')->nullable()->after('longitude');
                $table->index('created_by', 'payments_created_by_index');
            }
            if (!Schema::hasColumn('payments', 'deleted_by')) {
                // Quién eliminó el pago (pareja de deleted_at).
                $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
                $table->index('deleted_by', 'payments_deleted_by_index');
            }
            if (!Schema::hasColumn('payments', 'address')) {
                // Dirección textual donde se registró el pago (la manda la app
                // por geocodificación en el dispositivo, igual que en imágenes
                // del cliente). Las coordenadas siguen en latitude/longitude.
                $table->string('address', 500)->nullable()->after('longitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'created_by')) {
                $table->dropIndex('payments_created_by_index');
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('payments', 'deleted_by')) {
                $table->dropIndex('payments_deleted_by_index');
                $table->dropColumn('deleted_by');
            }
            if (Schema::hasColumn('payments', 'address')) {
                $table->dropColumn('address');
            }
        });
    }
};
