<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas (cuentas) del módulo Collection para la bitácora de registros diarios.
 *
 * Cada caja es un contenedor con nombre propio (ej: "Caja principal",
 * "Empleados", "Autos") sobre el que se registran movimientos
 * (ingreso | gasto | transferencia). El saldo NO se persiste como verdad:
 * se DERIVA de los movimientos (collection_daily_records.cashbox_id), igual
 * que el corte diario ya deriva su total sumando la bitácora. `opening_balance`
 * es el saldo inicial con que arranca la caja.
 *
 * Tabla NO particionada (como collection_daily_records): id nativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->create('collection_cashboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('name', 100);
            $table->string('currency', 3)->default('COP');
            $table->string('country_code', 2)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->dropIfExists('collection_cashboxes');
    }
};
