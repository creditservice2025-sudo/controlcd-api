<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de la visita y enlace al pago que la generó.
 *
 * La mayor parte de las gestiones no las va a escribir nadie: se derivan
 * automáticamente del pago, que ya captura ubicación en el 98% de los casos.
 * El cobrador solo registra a mano cuando NO hubo pago, que es justamente
 * cuando hace falta una explicación ("no estaba", "se negó").
 *
 * Distinguir el origen importa para leer la métrica: una visita 'pago' es un
 * hecho verificado por una transacción; una 'manual' es una declaración del
 * cobrador. No se deben mezclar al evaluar a alguien.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_visits')) {
            return;
        }

        Schema::table('client_visits', function (Blueprint $table) {
            if (!Schema::hasColumn('client_visits', 'source')) {
                $table->enum('source', ['manual', 'pago'])->default('manual')->after('result');
            }
            if (!Schema::hasColumn('client_visits', 'payment_id')) {
                $table->unsignedBigInteger('payment_id')->nullable()->after('client_comment_id');
                $table->index('payment_id');
            }
            if (!Schema::hasColumn('client_visits', 'gps_source')) {
                $table->string('gps_source', 16)->nullable()->after('accuracy');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_visits')) {
            return;
        }

        Schema::table('client_visits', function (Blueprint $table) {
            if (Schema::hasColumn('client_visits', 'payment_id')) {
                $table->dropIndex(['payment_id']);
                $table->dropColumn('payment_id');
            }
            if (Schema::hasColumn('client_visits', 'source')) {
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('client_visits', 'gps_source')) {
                $table->dropColumn('gps_source');
            }
        });
    }
};
