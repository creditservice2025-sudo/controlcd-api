<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega telegram_chat_id a companies para que el módulo Collection
 * (Deuda & Abono) pueda enviar notificaciones específicas a la empresa.
 *
 * - Columna nullable: si la empresa no la configura, fallback a in-app.
 * - Columna opt-in para Collection: NO modifica lógica de financing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'telegram_chat_id')) {
                $table->string('telegram_chat_id', 80)->nullable()->after('is_collection_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'telegram_chat_id')) {
                $table->dropColumn('telegram_chat_id');
            }
        });
    }
};
