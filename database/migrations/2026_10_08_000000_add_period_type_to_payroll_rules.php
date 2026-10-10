<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina: el tipo de nómina (diaria, semanal, quincenal, mensual) pasa a ser
 * parte de CADA REGLA, que es donde nace la regla de negocio. Una empresa
 * puede pagar semanal a los de una moneda y quincenal a los de otra, o darle
 * a un cobrador puntual un tipo distinto.
 *
 * Las reglas que ya existían heredan el tipo que tenía configurado su empresa,
 * así nada cambia de período al desplegar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_rules', 'period_type')) {
                $table->string('period_type', 20)->default('weekly')->after('active');
            }
            if (!Schema::hasColumn('payroll_rules', 'week_start_day')) {
                // 1 = lunes ... 7 = domingo. Solo se usa con period_type = weekly.
                $table->unsignedTinyInteger('week_start_day')->default(1)->after('period_type');
            }
        });

        foreach (DB::table('payroll_settings')->get(['company_id', 'period_type', 'week_start_day']) as $s) {
            DB::table('payroll_rules')->where('company_id', $s->company_id)->update([
                'period_type' => $s->period_type ?: 'weekly',
                'week_start_day' => (int) ($s->week_start_day ?: 1),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payroll_rules', fn (Blueprint $t) => $t->dropColumn(['period_type', 'week_start_day']));
    }
};
