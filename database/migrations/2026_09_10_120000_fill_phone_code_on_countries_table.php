<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Llena countries.phone_code, que existía vacío en los 21 países.
 *
 * Hace falta para armar el enlace de WhatsApp del vendedor: wa.me exige el
 * número en formato internacional, y sin el prefijo habría que pedírselo a mano
 * a quien carga el teléfono —que es justo el paso que se quiere evitar—.
 *
 * Solo escribe donde está en NULL: si alguien ya cargó un prefijo a mano, no se
 * lo pisa. Por eso también es segura de correr dos veces.
 */
return new class extends Migration
{
    /** Prefijo telefónico por nombre de país, tal como están guardados. */
    private const PREFIJOS = [
        'Argentina' => '54',
        'Bolivia' => '591',
        'Brasil' => '55',
        'Chile' => '56',
        'Colombia' => '57',
        'Costa Rica' => '506',
        'Cuba' => '53',
        // Dominicana y Puerto Rico comparten el 1 del plan norteamericano: lo
        // que las distingue es el código de área, que ya viaja en el número.
        'Dominicana' => '1',
        'Ecuador' => '593',
        'El Salvador' => '503',
        'Guatemala' => '502',
        'Haití' => '509',
        'Honduras' => '504',
        'México' => '52',
        'Nicaragua' => '505',
        'Panamá' => '507',
        'Paraguay' => '595',
        'Perú' => '51',
        'Puerto Rico' => '1',
        'Uruguay' => '598',
        'Venezuela' => '58',
    ];

    public function up(): void
    {
        foreach (self::PREFIJOS as $nombre => $prefijo) {
            DB::table('countries')
                ->where('name', $nombre)
                ->whereNull('phone_code')
                ->update(['phone_code' => $prefijo]);
        }
    }

    /**
     * Vuelve a NULL solo los que coinciden con lo que puso esta migración. Un
     * prefijo distinto lo cargó una persona y no es nuestro para borrarlo.
     */
    public function down(): void
    {
        foreach (self::PREFIJOS as $nombre => $prefijo) {
            DB::table('countries')
                ->where('name', $nombre)
                ->where('phone_code', $prefijo)
                ->update(['phone_code' => null]);
        }
    }
};
