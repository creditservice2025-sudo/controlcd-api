<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina: período configurable y descuento automático en el cierre de caja.
 *
 *  - payroll_settings.period_type: daily | weekly | biweekly (1-15 / 16-fin) | monthly.
 *  - payroll_settings.auto_pay_at_close: el último día laborable del período,
 *    la nómina del cobrador se descuenta sola de su caja al cerrar.
 *  - payrolls.period_type: con qué tipo de período se abrió esa nómina (las
 *    columnas week_start / week_end guardan el inicio y fin del período, sea
 *    cual sea su duración).
 *  - payroll_items.paid_via: cash_close (se descontó en el cierre) | manual
 *    (la pagó el administrador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_settings', 'period_type')) {
                $table->string('period_type', 20)->default('weekly')->after('company_id');
            }
            if (!Schema::hasColumn('payroll_settings', 'auto_pay_at_close')) {
                $table->boolean('auto_pay_at_close')->default(true)->after('week_start_day');
            }
        });

        Schema::table('payrolls', function (Blueprint $table) {
            if (!Schema::hasColumn('payrolls', 'period_type')) {
                $table->string('period_type', 20)->default('weekly')->after('week_end');
            }
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_items', 'paid_via')) {
                $table->string('paid_via', 20)->nullable()->after('paid_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn('paid_via'));
        Schema::table('payrolls', fn (Blueprint $t) => $t->dropColumn('period_type'));
        Schema::table('payroll_settings', fn (Blueprint $t) => $t->dropColumn(['period_type', 'auto_pay_at_close']));
    }
};
