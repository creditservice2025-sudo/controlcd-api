<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de VISITA / gestión de cobranza.
 *
 * Responde la pregunta "¿el cobrador pasó o no por este cliente hoy?", que no
 * se puede deducir de los pagos: un cliente visitado que no pagó es
 * exactamente el caso que el administrador necesita ver.
 *
 * Es una entidad SEPARADA de client_comments a propósito. Un supervisor puede
 * comentar desde la oficina y eso es legítimo, pero no es una visita; si se
 * mezclaran, la métrica de cobertura de ruta quedaría inservible desde el
 * primer día. La visita puede enlazar el comentario que se dejó (nullable).
 *
 * Notas de diseño:
 *  - uuid: lo genera el APK ANTES de enviar. Es la clave de idempotencia: el
 *    cobrador trabaja sin señal, la visita se encola y se reintenta; sin esto,
 *    cada reintento crearía un duplicado.
 *  - business_date/timestamp/timezone: mismo patrón que pagos y créditos. El
 *    día de negocio se congela en la zona del VENDEDOR, no del servidor.
 *  - distance_to_client_m: distancia entre donde se registró la visita y el
 *    domicilio guardado del cliente. Es lo que convierte el GPS en un control
 *    real y no en un adorno.
 *  - synced_at: cuándo llegó al servidor. Distinto de business_timestamp
 *    (cuándo ocurrió) en toda visita registrada sin señal.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_visits')) {
            return;
        }

        Schema::create('client_visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // idempotencia offline
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('user_id'); // quién registró
            $table->unsignedBigInteger('client_comment_id')->nullable();

            $table->enum('result', [
                'Cobrado',
                'Abono',
                'Promesa de pago',
                'No estaba',
                'Se negó',
                'Casa cerrada',
                'Ilocalizable',
                'Otro',
            ]);

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->text('address')->nullable();
            $table->unsignedInteger('distance_to_client_m')->nullable();

            $table->date('business_date');
            $table->dateTime('business_timestamp');
            $table->string('business_timezone', 64);
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'business_date']);
            $table->index(['seller_id', 'business_date']);

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('client_comment_id')->references('id')->on('client_comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_visits');
    }
};
