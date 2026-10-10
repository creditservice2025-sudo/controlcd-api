<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina: aprobación del administrador POR COBRADOR.
 *
 * El cobrador cierra su caja el último día y su nómina queda descontada en esa
 * liquidación; desde ahí espera la aprobación del administrador. approved_at
 * NULL en una línea pagada = "cerró caja, pendiente de aprobación".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_items', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('paid_via');
            }
            if (!Schema::hasColumn('payroll_items', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn(['approved_at', 'approved_by']));
    }
};
