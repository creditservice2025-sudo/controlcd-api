<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('telegram_enabled')->default(false)->after('logo_path');
            $table->string('telegram_chat_id', 50)->nullable()->after('telegram_enabled');
            $table->boolean('telegram_notify_new_client')->default(false)->after('telegram_chat_id');
            $table->boolean('telegram_notify_new_credit')->default(false)->after('telegram_notify_new_client');
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
