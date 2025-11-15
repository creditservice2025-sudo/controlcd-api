<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddCurrencyColumnToCountriesTable extends Migration
{
    public function up()
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('currency', 3)->after('name')->nullable();
        });

        $countryCurrencies = [
            'Argentina' => 'ARS', // Peso Argentino
            'Bolivia' => 'BOB',    // Boliviano
            'Brasil' => 'BRL',     // Real Brasileño
            'Chile' => 'CLP',      // Peso Chileno
            'Colombia' => 'COP',   // Peso Colombiano
            'Costa Rica' => 'CRC', // Colón Costarricense
            'Cuba' => 'CUP',       // Peso Cubano
            'Dominicana' => 'DOP', // Peso Dominicano
            'Ecuador' => 'USD',    // Dólar Estadounidense
            'El Salvador' => 'USD',// Dólar Estadounidense
            'Guatemala' => 'GTQ',  // Quetzal
            'Haití' => 'HTG',      // Gourde
            'Honduras' => 'HNL',   // Lempira
            'México' => 'MXN',     // Peso Mexicano
            'Nicaragua' => 'NIO',  // Córdoba
            'Panamá' => 'USD',     // Dólar Estadounidense
            'Paraguay' => 'PYG',   // Guaraní
            'Perú' => 'PEN',       // Sol
            'Puerto Rico' => 'USD',// Dólar Estadounidense
            'Uruguay' => 'UYU',    // Peso Uruguayo
            'Venezuela' => 'VES'   // Bolívar
        ];

        foreach ($countryCurrencies as $countryName => $currencyCode) {
            DB::table('countries')
                ->where('name', 'like', "%{$countryName}%")
                ->update(['currency' => $currencyCode]);
        }
    }

    public function down()
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
}