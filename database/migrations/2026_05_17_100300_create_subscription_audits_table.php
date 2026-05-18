<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de cambios en suscripciones: cambio de plan, status,
 * activación, suspensión, cancelación, renovación, etc.
 *
 * Compliance / soporte: cuando una empresa reclame "yo no autoricé el
 * cambio de plan", aquí queda registrado quién hizo qué y cuándo.
 * Independiente de los pagos (esos viven en subscription_payments).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_audits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')
                ->constrained('company_subscriptions')
                ->cascadeOnDelete();

            $table->string('event', 50)->comment('created, plan_changed, status_changed, renewed, cancelled, payment_recorded, etc.');

            // Snapshot del cambio
            $table->json('changes')->nullable()
                ->comment('{ field: { old: X, new: Y } }');

            // Quién hizo el cambio (puede ser null si fue automático por cron)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('user_email', 120)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'event']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_audits');
    }
};
