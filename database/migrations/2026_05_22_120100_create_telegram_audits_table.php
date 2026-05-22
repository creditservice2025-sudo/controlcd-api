<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cambios al módulo Telegram (separada de liquidation_audits
 * para que cada dominio tenga su propio trail). Registra QUIEN hizo QUE
 * cambio y CUANDO sobre la config Telegram de cada empresa.
 *
 * Acciones típicas:
 *   - feature_enabled / feature_disabled (SA)
 *   - event_toggled (SA)
 *   - chat_linked / chat_unlinked (empresa admin)
 *   - notifications_paused / notifications_resumed (empresa admin)
 *   - quiet_hours_updated (SA)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable(); // null si fue automático
            $table->string('action', 50);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_audits');
    }
};
