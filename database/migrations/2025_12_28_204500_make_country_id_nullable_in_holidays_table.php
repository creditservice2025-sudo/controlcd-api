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
        Schema::table('holidays', function (Blueprint $table) {
            // 1. Eliminar la llave foránea
            $table->dropForeign(['country_id']);
            
            // 2. Eliminar la restricción única anterior
            $table->dropUnique(['country_id', 'date']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            // 3. Hacer country_id nullable
            $table->unsignedBigInteger('country_id')->nullable()->change();
            
            // 4. Re-agregar la restricción única
            $table->unique(['country_id', 'date']);
            
            // 5. Re-agregar la llave foránea
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropUnique(['country_id', 'date']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable(false)->change();
            $table->unique(['country_id', 'date']);
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
        });
    }
};
