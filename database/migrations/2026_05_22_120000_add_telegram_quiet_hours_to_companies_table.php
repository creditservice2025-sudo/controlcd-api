<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quiet hours por empresa: ventana horaria durante la cual NO se envían
 * notificaciones Telegram (ej: 22:00 - 07:00). Si start y end están vacíos
 * o iguales, no se aplica filtro. Si start > end, se interpreta como
 * "atraviesa medianoche" (22:00 - 07:00 cubre noche completa).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->time('telegram_quiet_hours_start')->nullable()->after('telegram_notify_deleted_credit');
            $table->time('telegram_quiet_hours_end')->nullable()->after('telegram_quiet_hours_start');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['telegram_quiet_hours_start', 'telegram_quiet_hours_end']);
        });
    }
};
