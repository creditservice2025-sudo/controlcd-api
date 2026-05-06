<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            // Tope de monto para crédito NUEVO (primer crédito) que el cobrador
            // puede colocar. 0 = sin límite (consistente con la convención
            // existente en restrict_new_sales_amount).
            $table->integer('max_credit_amount_new')->default(0)->after('restrict_new_sales_amount');

            // Tope de monto para RENOVACIÓN (segundo crédito en adelante del
            // mismo cliente). Independiente del tope de nuevos, porque el
            // negocio suele permitir más en renovaciones (cliente conocido).
            $table->integer('max_credit_amount_renewal')->default(0)->after('max_credit_amount_new');
        });
    }

    public function down(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->dropColumn(['max_credit_amount_new', 'max_credit_amount_renewal']);
        });
    }
};
