<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de pagos sobre una suscripción.
 *
 * Cada pago extiende el ciclo (mueve end_date) y puede cambiar el
 * status. No almacenamos datos de tarjeta — solo referencias de pago
 * confirmado (PCI-DSS). Si en el futuro se integra una pasarela, se
 * agregará `gateway_transaction_id` y `gateway_provider`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')
                ->constrained('company_subscriptions')
                ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('COP');

            // Método de pago. Parametrizable a futuro vía enum extendido
            // o tabla de catálogo si se requieren más.
            $table->enum('method', [
                'transfer',     // transferencia bancaria
                'card',         // tarjeta (manual o pasarela)
                'cash',         // efectivo
                'pse',          // PSE Colombia
                'mercado_pago',
                'wompi',
                'stripe',
                'other',
            ])->default('transfer');

            $table->string('reference', 120)->nullable()
                ->comment('# transacción / # comprobante bancario');
            $table->string('receipt_url', 500)->nullable()
                ->comment('Path / URL al PDF o imagen del comprobante');

            $table->date('paid_at');
            $table->date('period_start')->comment('Inicio del periodo cubierto por este pago');
            $table->date('period_end')->comment('Fin del periodo cubierto por este pago');

            $table->text('notes')->nullable();

            // Auditoría
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            // Idempotencia (si se integra pasarela). Permite rechazar
            // doble-procesamiento del mismo webhook.
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            $table->index('subscription_id');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
