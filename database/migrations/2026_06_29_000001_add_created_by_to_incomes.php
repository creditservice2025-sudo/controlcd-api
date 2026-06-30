<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `created_by` a `incomes` para trazabilidad: quién REGISTRÓ el ingreso
 * (p. ej. un supervisor creándolo a nombre de un vendedor). El dueño sigue
 * siendo `user_id` (el vendedor); `created_by` es quién lo creó.
 *
 * Sigue el mismo patrón que expenses/payments: unsignedBigInteger nullable +
 * índice, SIN foreign key duro a propósito (la integridad se garantiza en la
 * capa de aplicación: created_by = Auth::id()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            if (!Schema::hasColumn('incomes', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('user_id');
                $table->index('created_by', 'incomes_created_by_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            if (Schema::hasColumn('incomes', 'created_by')) {
                $table->dropIndex('incomes_created_by_index');
                $table->dropColumn('created_by');
            }
        });
    }
};
