<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `liquidation_audits.user_id` pasa a ser NULLABLE.
 *
 * Motivo: la auditoría deja de depender de que haya un usuario logueado. El
 * auto-cierre (cron), el barrido de días atrasados y los comandos de
 * mantenimiento cambian el estado de una caja SIN sesión: hasta ahora eso
 * significaba, lisa y llanamente, que no se podía auditar (user_id NOT NULL).
 * Ese es el motivo por el que no se pudo reconstruir quién dejó abierto el
 * 2025-11-11 del vendedor 19.
 *
 * user_id NULL = "lo hizo el sistema" (el detalle del origen —comando, ruta—
 * va en la columna `changes`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('liquidation_audits')) {
            return;
        }

        // La FK hacia users bloquea el ALTER de la columna: se suelta, se
        // cambia el tipo y se vuelve a poner igual (mismo nombre, mismo
        // ON DELETE CASCADE) para no alterar el comportamiento existente.
        $this->dropForeignIfExists('liquidation_audits', 'liquidation_audits_user_id_foreign');

        DB::statement('ALTER TABLE `liquidation_audits` MODIFY `user_id` BIGINT UNSIGNED NULL');

        Schema::table('liquidation_audits', function ($table) {
            $table->foreign('user_id', 'liquidation_audits_user_id_foreign')
                ->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('liquidation_audits')) {
            return;
        }

        // Volver a NOT NULL exige que no queden filas del sistema. Se borran
        // solo esas (user_id NULL), que por definición nacieron después de
        // esta migración.
        DB::table('liquidation_audits')->whereNull('user_id')->delete();

        $this->dropForeignIfExists('liquidation_audits', 'liquidation_audits_user_id_foreign');

        DB::statement('ALTER TABLE `liquidation_audits` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('liquidation_audits', function ($table) {
            $table->foreign('user_id', 'liquidation_audits_user_id_foreign')
                ->references('id')->on('users')->onDelete('cascade');
        });
    }

    private function dropForeignIfExists(string $table, string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint]
        );

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }
};
