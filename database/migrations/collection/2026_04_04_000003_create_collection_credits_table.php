<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Estructura de Créditos (Préstamos) optimizada para PostgreSQL.
     * Mantiene compatibilidad total con el modelo Credit.php.
     */
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index(); // Vínculo al cliente en Postgres
            $table->unsignedBigInteger('seller_id')->index(); // Vínculo a la ruta en Postgres
            $table->unsignedBigInteger('company_id')->index(); // Vínculo a la empresa en MySQL
            
            $table->date('start_date');
            $table->date('end_date')->nullable();
            
            // Sello DevOps: Precisión decimal de alta gama (15, 2)
            $table->decimal('credit_value', 15, 2); 
            $table->integer('number_installments')->default(1);
            $table->string('payment_frequency')->default('diario'); 
            
            $table->decimal('total_interest', 15, 2)->default(0); 
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            
            $table->date('first_quota_date')->nullable();
            $table->jsonb('excluded_days')->nullable(); // Sello DevOps: JSONB de Postgres
            
            $table->decimal('micro_insurance_percentage', 5, 2)->default(0);
            $table->decimal('micro_insurance_amount', 15, 2)->default(0);
            
            $table->enum('status', ['pending', 'paid', 'defaulted'])->default('pending');
            $table->softDeletes();
            $table->timestamps();

            // Sello DevOps: Índices para velocidad de auditoría financiera
            $table->index(['client_id', 'status']);
            $table->index(['seller_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credits');
    }
};
