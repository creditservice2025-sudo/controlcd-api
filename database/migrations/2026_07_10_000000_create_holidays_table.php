<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días no laborables (feriados) POR EMPRESA y POR PAÍS.
 *
 * Cada empresa administra su propio calendario de feriados para los países
 * donde opera. Un feriado puede ser:
 *   - Recurrente (recurring = true): se repite todos los años en (month, day).
 *     Ej: 1 de enero, 25 de diciembre.
 *   - Fecha exacta (recurring = false): un único día concreto en `date`.
 *     Ej: feriados móviles (Semana Santa) o puentes de un año puntual.
 *
 * Consumido por App\Services\BusinessCalendar::isWorkingDate() para no generar
 * liquidación ni permitir el ingreso del cobrador esos días.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holidays')) {
            return; // idempotente para el deploy
        }

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('country_id');
            $table->string('description', 191);
            // true = recurrente anual por (month, day); false = fecha exacta en `date`.
            $table->boolean('recurring')->default(false);
            $table->unsignedTinyInteger('month')->nullable(); // 1-12 (si recurring)
            $table->unsignedTinyInteger('day')->nullable();    // 1-31 (si recurring)
            $table->date('date')->nullable();                  // Y-m-d (si NO recurring)
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');

            // Índices para el chequeo por empresa+país en cada evaluación de día.
            $table->index(['company_id', 'country_id', 'active'], 'holidays_scope_index');
            $table->index(['company_id', 'country_id', 'recurring', 'month', 'day'], 'holidays_recurring_index');
            $table->index(['company_id', 'country_id', 'date'], 'holidays_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
