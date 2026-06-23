<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueo de creación de nuevos créditos a nivel cliente.
 *
 * NO confundir con:
 *  - Cartera Irrecuperable: mueve créditos existentes a estado castigado.
 *  - Cliente inactivo: deshabilita al cliente por completo.
 *
 * Este flag NO afecta créditos vigentes (siguen operando y pagándose
 * normalmente). Solo impide la apertura de NUEVOS créditos / renovaciones.
 * Reversible: el admin puede desbloquear cuando corresponda.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('credit_block_active')->default(false)->after('status');
            // Categoría predefinida del bloqueo (ej: "mora_historica",
            // "doble_identidad", "decision_gerencial", "otro"). Se valida
            // en la capa de aplicación, no en BD, para permitir agregar
            // categorías sin migrar.
            $table->string('credit_block_reason', 60)->nullable()->after('credit_block_active');
            // Notas adicionales en texto libre (obligatorio si reason='otro').
            $table->text('credit_block_notes')->nullable()->after('credit_block_reason');
            $table->timestamp('credit_blocked_at')->nullable()->after('credit_block_notes');
            $table->foreignId('credit_blocked_by')->nullable()->after('credit_blocked_at')
                ->constrained('users')->nullOnDelete();

            $table->index('credit_block_active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['credit_blocked_by']);
            $table->dropColumn([
                'credit_block_active',
                'credit_block_reason',
                'credit_block_notes',
                'credit_blocked_at',
                'credit_blocked_by',
            ]);
        });
    }
};
