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
        Schema::create('credit_modifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('modification_type', ['schedule', 'frequency', 'initial_date'])->comment('Tipo de modificación aplicada');
            $table->json('old_value')->nullable()->comment('Valor anterior (puede ser fecha, frecuencia, etc)');
            $table->json('new_value')->nullable()->comment('Nuevo valor aplicado');
            $table->json('affected_installments')->nullable()->comment('Cuotas afectadas por el cambio');
            $table->text('notes')->nullable()->comment('Notas adicionales sobre la modificación');
            $table->timestamps();
            
            $table->foreign('credit_id')->references('id')->on('credits')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->index(['credit_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_modifications');
    }
};
