<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Impide DOS liquidaciones VIVAS para el mismo (seller_id, día), cerrando la
 * carrera "buscar-luego-crear" que producía duplicados (ej. dos filas 07-02).
 *
 * MySQL no soporta índices únicos parciales (WHERE deleted_at IS NULL), así que
 * se usa una columna generada: `active_date` = DATE(date) cuando la fila está
 * viva, y NULL cuando está soft-deleted. Como MySQL trata los NULL como
 * distintos en un índice único, el flujo de REAPERTURA (soft-delete + recrear
 * la misma fecha) sigue funcionando, pero dos filas vivas del mismo día chocan.
 *
 * Requiere que NO existan duplicados vivos previos (ya deduplicados aparte).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Columna generada VIRTUAL (no ocupa almacenamiento; se calcula al vuelo).
        DB::statement(
            "ALTER TABLE `liquidations`
             ADD COLUMN `active_date` DATE
             GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, DATE(`date`), NULL)) VIRTUAL"
        );

        DB::statement(
            "ALTER TABLE `liquidations`
             ADD UNIQUE INDEX `liquidations_seller_active_date_unique` (`seller_id`, `active_date`)"
        );
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `liquidations` DROP INDEX `liquidations_seller_active_date_unique`");
        DB::statement("ALTER TABLE `liquidations` DROP COLUMN `active_date`");
    }
};
