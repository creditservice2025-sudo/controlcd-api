<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice compuesto (action_type, action_id) en el historial de geolocalización.
 *
 * A partir de la bitácora del cliente, cada comentario registra DÓNDE se
 * escribió usando este historial (action_type = 'comment_created', action_id =
 * client_comments.id). El listado del hilo resuelve la ubicación de N
 * comentarios con un solo whereIn sobre esas dos columnas: sin este índice
 * MySQL hace full scan de una tabla que crece con cada pago, crédito y cliente.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_geolocation_histories')) {
            return;
        }

        Schema::table('client_geolocation_histories', function (Blueprint $table) {
            $table->index(['action_type', 'action_id'], 'cgh_action_type_action_id_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_geolocation_histories')) {
            return;
        }

        Schema::table('client_geolocation_histories', function (Blueprint $table) {
            $table->dropIndex('cgh_action_type_action_id_index');
        });
    }
};
