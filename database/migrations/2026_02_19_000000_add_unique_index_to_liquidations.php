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
        Schema::table('liquidations', function (Blueprint $table) {
            // Añadir índice único compuesto para evitar duplicados por vendedor y fecha
            // Solo para registros que no han sido eliminados suavemente
            $table->unique(['seller_id', 'date'], 'liquidations_seller_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropUnique('liquidations_seller_date_unique');
        });
    }
};
