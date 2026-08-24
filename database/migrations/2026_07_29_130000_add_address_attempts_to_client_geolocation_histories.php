<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de intentos de geocodificación inversa.
 *
 * La dirección de un comentario no puede quedar vacía, así que un comando
 * programado reintenta las que fallaron. Sin este contador, un punto que ningún
 * proveedor sabe resolver (zona sin datos en OSM, coordenada en el mar por un
 * GPS malo) se reintentaría para siempre, golpeando la API en cada corrida.
 *
 * A los 5 intentos el comando escribe las coordenadas como texto y deja de
 * insistir: el administrador ve algo verificable en un mapa y el sistema no
 * queda en un bucle infinito.
 */
return new class extends Migration {
    public function up(): void
    {
        if (
            !Schema::hasTable('client_geolocation_histories') ||
            Schema::hasColumn('client_geolocation_histories', 'address_attempts')
        ) {
            return;
        }

        Schema::table('client_geolocation_histories', function (Blueprint $table) {
            $table->unsignedTinyInteger('address_attempts')->default(0)->after('address');
        });
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('client_geolocation_histories') ||
            !Schema::hasColumn('client_geolocation_histories', 'address_attempts')
        ) {
            return;
        }

        Schema::table('client_geolocation_histories', function (Blueprint $table) {
            $table->dropColumn('address_attempts');
        });
    }
};
