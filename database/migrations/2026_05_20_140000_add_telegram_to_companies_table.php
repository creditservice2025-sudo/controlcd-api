<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Cada columna va con su guard porque `telegram_chat_id` ya la crea
        // 2026_04_26_100000_add_telegram_chat_id_to_companies (del módulo
        // Collection). Desde que las dos conviven en la misma línea, esta
        // abortaba el batch con "Duplicate column name 'telegram_chat_id'",
        // dejaba la base a medias y migrate:fresh no podía terminar: la suite
        // de tests no arrancaba y un entorno nuevo no se podía levantar.
        // El guard no cambia el esquema resultante ni tiene efecto donde la
        // migración ya corrió: Laravel no la vuelve a ejecutar.
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'telegram_enabled')) {
                $table->boolean('telegram_enabled')->default(false)->after('logo_path');
            }
            if (!Schema::hasColumn('companies', 'telegram_chat_id')) {
                $table->string('telegram_chat_id', 50)->nullable()->after('telegram_enabled');
            }
            if (!Schema::hasColumn('companies', 'telegram_notify_new_client')) {
                $table->boolean('telegram_notify_new_client')->default(false)->after('telegram_chat_id');
            }
            if (!Schema::hasColumn('companies', 'telegram_notify_new_credit')) {
                $table->boolean('telegram_notify_new_credit')->default(false)->after('telegram_notify_new_client');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_enabled',
                'telegram_chat_id',
                'telegram_notify_new_client',
                'telegram_notify_new_credit',
            ]);
        });
    }
};
