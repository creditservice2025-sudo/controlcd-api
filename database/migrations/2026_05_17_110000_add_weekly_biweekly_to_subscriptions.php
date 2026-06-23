<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía los ciclos de cobro a: weekly | biweekly | monthly | annual.
 *
 * Para `company_subscriptions.billing_cycle`, no se puede ALTER ENUM
 * de MySQL sin recrear la columna (Doctrine DBAL en Laravel 11 no lo
 * soporta directo), así que se usa raw SQL.
 *
 * Para `plans` se agregan dos columnas nuevas de precios:
 *   - weekly_price
 *   - biweekly_price
 * Ambas nullable: si NULL, ese ciclo no se ofrece para ese plan
 * (mismo patrón que monthly_price/annual_price).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('weekly_price', 12, 2)->nullable()->after('description');
            $table->decimal('biweekly_price', 12, 2)->nullable()->after('weekly_price');
        });

        // Expand enum on company_subscriptions.billing_cycle.
        DB::statement(
            "ALTER TABLE company_subscriptions
             MODIFY COLUMN billing_cycle ENUM('weekly','biweekly','monthly','annual') NOT NULL DEFAULT 'monthly'"
        );
    }

    public function down(): void
    {
        // Revertir enum primero (los valores deben coincidir con datos existentes).
        DB::statement(
            "ALTER TABLE company_subscriptions
             MODIFY COLUMN billing_cycle ENUM('monthly','annual') NOT NULL DEFAULT 'monthly'"
        );

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['weekly_price', 'biweekly_price']);
        });
    }
};
