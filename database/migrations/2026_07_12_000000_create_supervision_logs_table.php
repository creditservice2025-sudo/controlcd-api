<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de SUPERVISIÓN por ruta: registra cuándo un Supervisor (rol 6)
 * empezó y terminó de supervisar una ruta (seller). Una fila = un tramo de
 * supervisión continuo sobre una ruta.
 *
 * Se abre al seleccionar/cambiar de ruta y se cierra al cambiar de ruta o
 * cerrar sesión (SupervisorLockService). Complementa el lock efímero de cache
 * (que solo dice "supervisando AHORA") con un HISTORIAL persistente de
 * "quién supervisó qué ruta, desde cuándo hasta cuándo".
 *
 * ended_at NULL = supervisión en curso. end_reason indica cómo terminó:
 * 'switch' (cambió de ruta) | 'logout' (cerró sesión).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supervision_logs')) {
            return; // idempotente para el deploy
        }

        Schema::create('supervision_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supervisor_user_id'); // users.id (rol 6)
            $table->unsignedBigInteger('seller_id');          // ruta supervisada
            $table->unsignedBigInteger('company_id')->nullable(); // tenant (desde el seller)
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();        // NULL = en curso
            $table->string('end_reason', 20)->nullable();     // switch | logout
            $table->timestamps();

            // Historial por ruta (uso principal: expandir fila en Rutas Activas).
            $table->index(['seller_id', 'started_at']);
            // Historial por supervisor.
            $table->index(['supervisor_user_id', 'started_at']);
            // Buscar rápido la sesión ABIERTA de un supervisor al cerrar/cambiar.
            $table->index(['supervisor_user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_logs');
    }
};
