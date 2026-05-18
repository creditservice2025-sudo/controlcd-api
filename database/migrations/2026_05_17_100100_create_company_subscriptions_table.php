<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripción activa de una empresa a un plan.
 *
 * Una empresa solo puede tener UNA suscripción "active" o "trial" a la
 * vez (constraint via app + unique en migración aparte si se prefiere
 * a nivel BD). El historial de suscripciones anteriores queda en
 * registros con status=cancelled/expired.
 *
 * Snapshot de precio: amount y currency se copian del plan en el
 * momento de crear la suscripción. Si el plan cambia de precio
 * después, esta empresa sigue pagando el precio "snapshot" hasta que
 * el admin la migre a otro plan o cambie el monto manualmente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();

            // Estado del ciclo de vida de la suscripción.
            // - trial:     periodo de prueba, no requiere pago
            // - active:    al día, operando
            // - past_due:  vencido, en gracia (puede operar)
            // - suspended: bloqueado por falta de pago (NO opera)
            // - cancelled: el cliente o admin la canceló (expira al fin)
            // - expired:   end_date pasó sin pago ni renovación
            $table->enum('status', ['trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired'])
                ->default('trial');

            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');

            // SNAPSHOT del precio al momento de suscribirse.
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('COP');

            // Fechas del ciclo actual
            $table->date('start_date');
            $table->date('end_date')->comment('Fin del ciclo actual / próxima renovación');
            $table->date('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->boolean('auto_renew')->default(true);

            $table->text('notes')->nullable()->comment('Observaciones del admin');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index('end_date')->comment('Para cron de renovaciones / past_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
    }
};
