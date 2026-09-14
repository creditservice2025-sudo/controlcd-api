<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traza de cambios de las cajas del módulo Collection.
 *
 * Una caja es el contenedor del dinero: renombrarla o darla de baja cambia cómo
 * se lee todo el histórico de movimientos, así que el cambio en sí tiene que
 * quedar registrado. Mismo patrón que `collection_client_audits`: un evento por
 * fila, con el snapshot viejo y el nuevo en `changes`.
 *
 * `changes` guarda SIEMPRE el nombre de la caja al momento del evento, para que
 * el historial se pueda leer aunque la caja ya no exista.
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        // Idempotente a propósito: el servidor de test ya tiene la tabla (la
        // creó el código de otra rama), así que un create() a secas abortaba el
        // batch de migraciones entero.
        if (Schema::connection($this->connection)->hasTable('collection_cashbox_audits')) {
            return;
        }

        Schema::connection($this->connection)->create('collection_cashbox_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('cashbox_id')->index();
            // created | updated | deactivated | deleted
            $table->string('action', 30);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('changes')->nullable();
            $table->timestamps();

            // El listado del historial es siempre "de esta empresa, lo último
            // primero": sin este índice se degrada a scan a medida que crece.
            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_cashbox_audits');
    }
};
