<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('business_timestamp')->nullable()->after('created_at');
            $table->date('business_date')->nullable()->after('business_timestamp');
            $table->string('business_timezone', 64)->nullable()->after('business_date');
        });

        // Migrar datos existentes
        DB::statement("
            UPDATE payments 
            SET business_timestamp = created_at,
                business_date = COALESCE(payment_date, DATE(created_at)),
                business_timezone = COALESCE(client_timezone, 'America/Lima')
            WHERE business_timestamp IS NULL
        ");

        // Hacer business_date NOT NULL después de migrar
        Schema::table('payments', function (Blueprint $table) {
            $table->date('business_date')->nullable(false)->change();
        });

        // Crear índice para mejorar performance de queries
        Schema::table('payments', function (Blueprint $table) {
            $table->index('business_date', 'payments_business_date_index');
            $table->index(['business_date', 'deleted_at'], 'payments_business_date_deleted_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_business_date_index');
            $table->dropIndex('payments_business_date_deleted_index');
            $table->dropColumn(['business_timestamp', 'business_date', 'business_timezone']);
        });
    }
};
