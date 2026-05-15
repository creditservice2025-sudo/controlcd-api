<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            // Mínimo de cuotas pagadas del crédito anterior para permitir
            // crear otro crédito al mismo cliente (renovación).
            // 0 = no se exige.
            $table->integer('renewal_min_quotas_paid')->default(0)->after('max_credit_amount_renewal');

            // Máximo de créditos vigentes simultáneos por cliente.
            // 0 = sin límite.
            // 1 = solo permite un crédito por cliente (bloquea renovaciones
            //     mientras tenga uno activo).
            $table->integer('max_credits_per_client')->default(0)->after('renewal_min_quotas_paid');
        });
    }

    public function down(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->dropColumn(['renewal_min_quotas_paid', 'max_credits_per_client']);
        });
    }
};
