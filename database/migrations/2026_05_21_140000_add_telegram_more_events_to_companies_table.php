<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos adicionales del módulo Telegram, controlados por SA:
 *   - notify_new_expense:      cuando se registra un gasto
 *   - notify_deleted_expense:  cuando se elimina un gasto
 *   - notify_deleted_credit:   cuando se elimina un crédito
 *
 * notify_new_client y notify_new_credit ya existían. notify_new_credit
 * se reinterpreta como "crédito a cliente existente" (no se dispara cuando
 * el crédito nace junto con un cliente nuevo, eso lo cubre notify_new_client).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('telegram_notify_new_expense')->default(false)->after('telegram_notify_new_credit');
            $table->boolean('telegram_notify_deleted_expense')->default(false)->after('telegram_notify_new_expense');
            $table->boolean('telegram_notify_deleted_credit')->default(false)->after('telegram_notify_deleted_expense');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_notify_new_expense',
                'telegram_notify_deleted_expense',
                'telegram_notify_deleted_credit',
            ]);
        });
    }
};
