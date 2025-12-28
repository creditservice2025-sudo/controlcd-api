<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Campos de plan
            $table->enum('plan_type', ['free', 'full'])->default('free')->after('logo_path');
            $table->date('plan_start_date')->nullable()->after('plan_type');
            $table->date('plan_end_date')->nullable()->after('plan_start_date');
            
            // Campos de verificación WhatsApp
            $table->boolean('whatsapp_verified')->default(false)->after('plan_end_date');
            $table->string('last_verification_code', 6)->nullable()->after('whatsapp_verified');
            $table->timestamp('verification_code_expires_at')->nullable()->after('last_verification_code');
            
            // Índices para performance
            $table->index('plan_type');
            $table->index('whatsapp_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Eliminar índices primero
            $table->dropIndex(['plan_type']);
            $table->dropIndex(['whatsapp_verified']);
            
            // Eliminar columnas
            $table->dropColumn([
                'plan_type',
                'plan_start_date',
                'plan_end_date',
                'whatsapp_verified',
                'last_verification_code',
                'verification_code_expires_at'
            ]);
        });
    }
};
