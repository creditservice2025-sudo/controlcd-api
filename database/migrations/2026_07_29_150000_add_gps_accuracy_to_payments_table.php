<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precisión y origen del GPS en los pagos.
 *
 * POR QUÉ: hoy `payments` guarda latitude/longitude pero no cuán confiable es
 * ese punto. Cuando el GPS satelital no engancha, el APK reintenta con
 * `enableHighAccuracy: false` y la ubicación pasa a venir de la torre celular,
 * con errores de kilómetros. Ambas ubicaciones se guardan idénticas, así que
 * después es imposible separar el dato bueno del inservible.
 *
 * Medición que motivó el cambio (802.409 pagos, 98% con GPS): la distancia
 * mediana entre el pago y el domicilio del cliente es de 1.913 m, y los pagos
 * de un mismo cliente están dispersos entre sí con mediana de 21 km. Sin
 * `gps_accuracy` no hay forma de saber cuáles de esos puntos son utilizables.
 *
 * Sin este dato NO se puede calibrar ninguna alarma de "fuera de rango": se
 * estaría acusando a cobradores por un error de medición del teléfono.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'gps_accuracy')) {
                // Radio de error en metros que reporta el dispositivo.
                $table->decimal('gps_accuracy', 8, 2)->nullable()->after('longitude');
            }
            if (!Schema::hasColumn('payments', 'gps_source')) {
                // 'gps' (satélite, preciso) | 'network' (torre/wifi, impreciso)
                $table->string('gps_source', 16)->nullable()->after('gps_accuracy');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'gps_accuracy')) {
                $table->dropColumn('gps_accuracy');
            }
            if (Schema::hasColumn('payments', 'gps_source')) {
                $table->dropColumn('gps_source');
            }
        });
    }
};
