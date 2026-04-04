<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabla de Vendedores (Rutas) optimizada para PostgreSQL.
     * Mantiene compatibilidad total con el modelo actual.
     */
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Requerido por el modelo
            $table->unsignedBigInteger('user_id')->nullable()->index(); // ID del usuario (MySQL)
            $table->unsignedBigInteger('company_id')->index(); // ID de la empresa (MySQL)
            $table->unsignedBigInteger('city_id')->nullable()->index(); // ID de la ciudad (MySQL)
            
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('module_type', ['collection', 'financing'])->default('collection');
            
            $table->softDeletes();
            $table->timestamps();

            // Sello DevOps: Índice compuesto para velocidad operacional
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
