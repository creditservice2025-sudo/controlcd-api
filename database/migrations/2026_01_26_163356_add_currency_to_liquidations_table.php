<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddCurrencyToLiquidationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->string('currency', 3)->after('seller_id')->nullable();
        });

        // Poblar el campo currency para liquidaciones existentes
        // basándose en el país del vendedor
        DB::statement("
            UPDATE liquidations l
            JOIN sellers s ON l.seller_id = s.id
            JOIN cities c ON s.city_id = c.id
            JOIN countries co ON c.country_id = co.id
            SET l.currency = co.currency
            WHERE l.currency IS NULL AND co.currency IS NOT NULL
        ");

        // Establecer PEN como default para liquidaciones sin país configurado
        DB::statement("
            UPDATE liquidations
            SET currency = 'PEN'
            WHERE currency IS NULL
        ");

        // Hacer el campo NOT NULL después de poblar datos
        Schema::table('liquidations', function (Blueprint $table) {
            $table->string('currency', 3)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
}
