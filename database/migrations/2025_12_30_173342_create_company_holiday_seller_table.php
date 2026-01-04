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
        Schema::create('company_holiday_seller', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('holiday_id')->constrained()->onDelete('cascade');
            $table->foreignId('seller_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();
            
            // Un vendedor no puede estar asignado dos veces al mismo feriado de la misma empresa
            $table->unique(['company_id', 'holiday_id', 'seller_id'], 'company_holiday_seller_unique');
            // Índice para mejorar performance en consultas
            $table->index(['company_id', 'holiday_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_holiday_seller');
    }
};
