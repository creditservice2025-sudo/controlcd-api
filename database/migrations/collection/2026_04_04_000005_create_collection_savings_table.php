<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabla de Ahorros diseñada para integridad total en PostgreSQL.
     */
    public function up(): void
    {
        Schema::create('savings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index(); // Vínculo al cliente en Postgres
            $table->unsignedBigInteger('company_id')->index(); // Vínculo a la empresa en MySQL
            
            // Sello DevOps: Precisión decimal (15, 2)
            $table->decimal('amount', 15, 2); 
            $table->enum('type', ['deposit', 'withdrawal'])->default('deposit');
            $table->dateTime('transaction_date');
            $table->text('note')->nullable();
            
            $table->softDeletes();
            $table->timestamps();

            // Sello DevOps: Índice para velocidad de auditoría por cliente
            $table->index(['client_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings');
    }
};
