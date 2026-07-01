<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de última edición del cliente: registra QUIÉN lo editó y con
 * qué rol (p. ej. el supervisor que edita el cliente del vendedor que
 * supervisa). Sin FK, siguiendo el patrón del proyecto (igual que created_by).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by_role');
                $table->index('updated_by');
            }
            if (!Schema::hasColumn('clients', 'updated_by_role')) {
                $table->unsignedSmallInteger('updated_by_role')->nullable()->after('updated_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'updated_by')) {
                $table->dropIndex(['updated_by']);
                $table->dropColumn('updated_by');
            }
            if (Schema::hasColumn('clients', 'updated_by_role')) {
                $table->dropColumn('updated_by_role');
            }
        });
    }
};
