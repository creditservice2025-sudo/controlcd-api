<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-vinculación Telegram vía deep link: el admin de la empresa hace
 * clic en "Vincular", el sistema genera un token random temporal y lo
 * guarda aquí. Cuando el usuario abre t.me/bot?start=<token>, el webhook
 * recibe el mensaje, busca la empresa por este token, captura el chat_id
 * de quien lo envió y lo persiste. Token de un solo uso, expira en 15 min.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('telegram_link_token', 64)->nullable()->after('telegram_chat_id');
            $table->timestamp('telegram_link_expires_at')->nullable()->after('telegram_link_token');
            $table->index('telegram_link_token', 'companies_telegram_link_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('companies_telegram_link_token_idx');
            $table->dropColumn(['telegram_link_token', 'telegram_link_expires_at']);
        });
    }
};
