<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Esquema de Clientes rediseñado para PostgreSQL.
     * Mantiene compatibilidad total con el modelo Client.php.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Requerido por el modelo
            $table->unsignedBigInteger('seller_id')->index(); // Vínculo a la ruta en Postgres
            $table->unsignedBigInteger('company_id')->index(); // Vínculo a la empresa en MySQL
            
            $table->string('name');
            $table->string('dni')->index(); // Indexado para velocidad
            $table->string('address')->nullable();
            $table->string('reference')->nullable();
            $table->jsonb('geolocation')->nullable(); // Sello DevOps: JSONB nativo de Postgres
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            
            $table->integer('routing_order')->nullable(); // Sello DevOps: Imprescindible para el orden de rutas
            $table->boolean('needs_update')->default(false);
            
            $table->softDeletes();
            $table->timestamps();

            // Sello DevOps: Índice compuesto para velocidad de búsqueda operacional
            $table->index(['seller_id', 'dni']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
