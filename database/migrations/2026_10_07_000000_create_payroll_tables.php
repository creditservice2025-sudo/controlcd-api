<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina semanal de cobradores.
 *
 *  - payroll_settings: parámetros por empresa (día en que arranca la semana).
 *  - payroll_rules:    regla de comisión. Con seller_id NULL es la regla general
 *                      de la empresa para una MONEDA; con seller_id es la
 *                      excepción de ese vendedor. La moneda va en la regla
 *                      porque una empresa puede tener rutas en varios países y
 *                      los montos fijos / tramos no son comparables entre sí.
 *  - payrolls:         la nómina de una semana de una empresa.
 *  - payroll_items:    una fila por vendedor con la FOTO del cálculo (bases,
 *                      regla aplicada y montos). No se recalcula sola: un pago
 *                      anulado después no cambia una nómina ya aprobada.
 *  - payroll_item_adjustments: bonos y descuentos manuales (adelantos,
 *                      faltantes, etc.).
 *
 * Idempotente (Schema::hasTable) para poder re-ejecutarla sin romper.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_settings')) {
            Schema::create('payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                // 1 = lunes ... 7 = domingo (ISO-8601).
                $table->unsignedTinyInteger('week_start_day')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payroll_rules')) {
            Schema::create('payroll_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('currency', 3);
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->boolean('active')->default(true);

                // Comisión por recaudo: none | percentage | tiers
                $table->string('collection_mode', 20)->default('none');
                $table->decimal('collection_percentage', 7, 3)->default(0);
                // [{from, to|null, percentage, bonus}] — el tramo alcanzado
                // aplica su % a TODO el recaudo y suma su bono fijo.
                $table->json('collection_tiers')->nullable();

                // Comisión por colocación: none | capital | interest
                $table->string('placement_mode', 20)->default('none');
                $table->decimal('placement_percentage', 7, 3)->default(0);

                $table->decimal('fixed_salary', 14, 2)->default(0);
                $table->decimal('allowance', 14, 2)->default(0);
                // [{concept, amount}] descuentos fijos de cada semana.
                $table->json('fixed_deductions')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'currency', 'seller_id'], 'payroll_rules_scope_idx');
            });
        }

        if (!Schema::hasTable('payrolls')) {
            Schema::create('payrolls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->date('week_start');
                $table->date('week_end');
                // draft | approved | paid | void
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->string('void_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'week_start'], 'payrolls_company_week_idx');
            });
        }

        if (!Schema::hasTable('payroll_items')) {
            Schema::create('payroll_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payroll_id');
                $table->unsignedBigInteger('seller_id');
                $table->unsignedBigInteger('seller_user_id')->nullable();
                $table->string('seller_name');
                $table->string('currency', 3);

                $table->unsignedBigInteger('rule_id')->nullable();
                $table->json('rule_snapshot')->nullable();

                // Bases de la semana
                $table->decimal('collection_base', 14, 2)->default(0);
                $table->decimal('placement_capital', 14, 2)->default(0);
                $table->decimal('placement_interest', 14, 2)->default(0);
                $table->unsignedSmallInteger('days_with_collection')->default(0);
                // Días de la semana cuya liquidación aún no está aprobada.
                $table->json('pending_days')->nullable();

                // Conceptos calculados
                $table->decimal('collection_commission', 14, 2)->default(0);
                $table->decimal('tier_bonus', 14, 2)->default(0);
                $table->decimal('placement_commission', 14, 2)->default(0);
                $table->decimal('fixed_salary', 14, 2)->default(0);
                $table->decimal('allowance', 14, 2)->default(0);
                $table->decimal('bonuses_total', 14, 2)->default(0);
                $table->decimal('fixed_deductions_total', 14, 2)->default(0);
                $table->decimal('manual_deductions_total', 14, 2)->default(0);

                $table->decimal('gross', 14, 2)->default(0);
                $table->decimal('deductions', 14, 2)->default(0);
                $table->decimal('net', 14, 2)->default(0);

                // pending | paid | no_payment (neto <= 0)
                $table->string('status', 20)->default('pending');
                // Gasto con el que el pago salió de la caja del cobrador.
                $table->unsignedBigInteger('expense_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->string('pay_error')->nullable();
                $table->timestamps();

                $table->unique(['payroll_id', 'seller_id'], 'payroll_items_payroll_seller_uq');
                $table->index('expense_id');
            });
        }

        if (!Schema::hasTable('payroll_item_adjustments')) {
            Schema::create('payroll_item_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payroll_item_id');
                // bonus | deduction
                $table->string('type', 20);
                $table->string('concept');
                $table->decimal('amount', 14, 2);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index('payroll_item_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_item_adjustments');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('payroll_rules');
        Schema::dropIfExists('payroll_settings');
    }
};
