<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina: trazabilidad de las reglas de comisión. created_by / updated_by ya
 * existían; faltaba saber QUIÉN eliminó una regla (el borrado es suave, así que
 * la regla sigue en la tabla con su deleted_at).
 *
 * Idempotente (Schema::hasColumn) para poder re-ejecutarla sin romper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_rules', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('updated_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_rules', 'deleted_by')) {
                $table->dropColumn('deleted_by');
            }
        });
    }
};
