<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de eliminación de gastos: registra QUIÉN eliminó el gasto
 * (p. ej. un supervisor borrando un gasto del vendedor que supervisa).
 * Sin FK, siguiendo el patrón del proyecto (igual que payments/incomes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('expenses', 'deleted_by')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('user_id');
                $table->index('deleted_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'deleted_by')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropIndex(['deleted_by']);
                $table->dropColumn('deleted_by');
            });
        }
    }
};
