<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de EDICIONES de registros diarios (correcciones por equivocación).
 * Guarda la trazabilidad exigida: monto anterior → monto actual, delta (lo que
 * afectó la caja), campos cambiados, observación OBLIGATORIA y quién lo hizo.
 * Tabla NO particionada (igual que collection_daily_records); id por secuencia
 * nativa vía $table->id().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->create('collection_daily_record_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedBigInteger('daily_record_id')->index();
            $table->unsignedBigInteger('user_id')->index(); // quién realizó el ajuste
            $table->string('action', 20)->default('update'); // update | delete (futuro)
            // Trazabilidad del monto (lo que afectó la caja).
            $table->decimal('old_amount', 15, 2)->nullable();
            $table->decimal('new_amount', 15, 2)->nullable();
            $table->decimal('delta', 15, 2)->nullable(); // new - old
            // Otros campos editables.
            $table->string('old_category', 50)->nullable();
            $table->string('new_category', 50)->nullable();
            $table->string('old_description', 500)->nullable();
            $table->string('new_description', 500)->nullable();
            // Motivo obligatorio del ajuste.
            $table->text('observation');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['company_id', 'daily_record_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->dropIfExists('collection_daily_record_audits');
    }
};
