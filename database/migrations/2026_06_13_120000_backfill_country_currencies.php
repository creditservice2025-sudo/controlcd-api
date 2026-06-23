<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de la columna countries.currency.
 *
 * La migración original (2025_05_19_043825_add_currency_column_to_countries_table)
 * agregó la columna y la pobló por nombre, pero en varios entornos los países se
 * sembraron DESPUÉS de correrla, por lo que quedaron con currency = NULL. Como
 * consecuencia toda la cadena vendedor -> ciudad -> país -> currency caía al
 * fallback 'PEN' y la UI mostraba "S/" incluso para vendedores de Bolivia (Bs),
 * Colombia ($), etc.
 *
 * Esta migración es idempotente: solo rellena los países cuya moneda aún es NULL,
 * sin pisar valores ya configurados manualmente.
 */
class BackfillCountryCurrencies extends Migration
{
    public function up()
    {
        // Códigos ISO 4217 por país (mismos que usan los core bancarios).
        $countryCurrencies = [
            'Argentina'    => 'ARS', // Peso Argentino
            'Bolivia'      => 'BOB', // Boliviano (Bs)
            'Brasil'       => 'BRL', // Real Brasileño
            'Chile'        => 'CLP', // Peso Chileno
            'Colombia'     => 'COP', // Peso Colombiano
            'Costa Rica'   => 'CRC', // Colón Costarricense
            'Cuba'         => 'CUP', // Peso Cubano
            'Dominicana'   => 'DOP', // Peso Dominicano
            'Ecuador'      => 'USD', // Dólar Estadounidense
            'El Salvador'  => 'USD', // Dólar Estadounidense
            'Guatemala'    => 'GTQ', // Quetzal
            'Haití'        => 'HTG', // Gourde
            'Honduras'     => 'HNL', // Lempira
            'México'       => 'MXN', // Peso Mexicano
            'Nicaragua'    => 'NIO', // Córdoba
            'Panamá'       => 'USD', // Dólar Estadounidense
            'Paraguay'     => 'PYG', // Guaraní
            'Perú'         => 'PEN', // Sol
            'Puerto Rico'  => 'USD', // Dólar Estadounidense
            'Uruguay'      => 'UYU', // Peso Uruguayo
            'Venezuela'    => 'VES', // Bolívar
        ];

        foreach ($countryCurrencies as $countryName => $currencyCode) {
            DB::table('countries')
                ->where('name', 'like', "%{$countryName}%")
                ->whereNull('currency') // idempotente: no pisa valores ya seteados
                ->update(['currency' => $currencyCode]);
        }
    }

    public function down()
    {
        // No-op: revertir un backfill de datos (volver a NULL) rompería la UI
        // y no aporta valor. La columna se mantiene; los valores quedan.
    }
}
