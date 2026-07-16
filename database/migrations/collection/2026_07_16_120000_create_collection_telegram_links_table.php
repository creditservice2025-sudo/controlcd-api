<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo entre un chat de Telegram y un usuario/empresa de Collection, más el
 * estado de la conversación guiada para crear movimientos (ej. /gasto).
 *
 * El bot de Collection es SEPARADO del bot de notificaciones del núcleo. Un
 * cobrador vincula su Telegram una vez (compartiendo su número) y luego crea
 * gastos por una plantilla guiada, con foto de comprobante opcional.
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('collection_telegram_links', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();       // id del chat de Telegram
            $table->unsignedBigInteger('user_id')->nullable(); // usuario (MySQL) vinculado
            $table->unsignedInteger('company_id')->nullable();
            $table->string('phone', 30)->nullable();
            // Identidad de Telegram del chat (para trazabilidad de quién carga).
            $table->string('tg_username', 64)->nullable();
            $table->string('tg_first_name', 120)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('country_code', 2)->nullable();
            // Estado de la conversación: idle | awaiting_code | m_amount | m_category | m_description | m_photo
            $table->string('state', 20)->default('idle');
            $table->jsonb('draft')->nullable();            // borrador del movimiento en curso
            // Verificación por código (para número escrito a mano): OTP enviado por email.
            $table->string('verify_code', 6)->nullable();
            $table->timestamp('verify_expires_at')->nullable();
            $table->timestamp('linked_at')->nullable();    // vinculado solo cuando != null
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_telegram_links');
    }
};
