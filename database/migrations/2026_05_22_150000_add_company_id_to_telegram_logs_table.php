<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula cada log al company_id origen para poder filtrar el historial
 * desde el panel de cada empresa. Hasta ahora solo guardábamos chat_id,
 * que es el destinatario — no permitía agrupar por empresa origen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('telegram_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
            $table->index('company_id', 'telegram_logs_company_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_logs', function (Blueprint $table) {
            $table->dropIndex('telegram_logs_company_id_idx');
            $table->dropColumn('company_id');
        });
    }
};
