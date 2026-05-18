<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de planes de suscripción del sistema.
 *
 * Diseño parametrizable: en lugar de "Free/Pro/Enterprise" hardcodeado,
 * cada plan define sus propios precios, límites y features. El admin
 * Super-Admin puede crear/editar planes desde el panel; las empresas
 * se suscriben a uno de los planes activos.
 *
 * Idempotencia de cambios: si el precio de un plan cambia después de
 * que una empresa se suscribió, su `company_subscriptions` mantiene un
 * SNAPSHOT del precio original. Cambiar el plan no le sube el cobro
 * automáticamente; el admin decide cuándo migrar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('name', 80)->unique();
            $table->string('slug', 80)->unique()->comment('Identificador estable para código (free, pro, enterprise, custom-xyz)');
            $table->string('description')->nullable();

            // Precios. Ambos opcionales para permitir planes solo-mensual
            // o solo-anual. Si NULL → ese ciclo no se ofrece.
            $table->decimal('monthly_price', 12, 2)->nullable();
            $table->decimal('annual_price', 12, 2)->nullable();
            $table->char('currency', 3)->default('COP')->comment('ISO 4217: COP, PEN, BOB, USD, ...');

            // Periodos parametrizables
            $table->unsignedSmallInteger('trial_days')->default(0)->comment('0 = sin trial');
            $table->unsignedSmallInteger('grace_days')->default(7)->comment('Días de gracia tras vencer antes de pasar a suspended');

            // Límites operativos. NULL = ilimitado.
            $table->unsignedInteger('max_sellers')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_credits_per_month')->nullable();
            $table->unsignedInteger('max_active_credits')->nullable();
            $table->unsignedInteger('max_clients')->nullable();

            // Features. JSON libre para futuras banderas sin migrar.
            // Ej: { "reports_pdf": true, "api_access": false, "whatsapp_notifications": true }
            $table->json('features')->nullable();

            // Visibilidad
            $table->boolean('is_active')->default(true)->comment('Si está deshabilitado no se puede asignar a nuevas empresas');
            $table->boolean('is_public')->default(true)->comment('Si false, solo se asigna por admin (planes custom)');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
