<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuesto mensual por categoría de gasto (Deuda & Abono).
 *
 * Cada fila = tope mensual para una categoría de una empresa. El módulo compara
 * el gasto del mes en curso contra este tope y muestra alerta al 80% / 100%.
 * Un presupuesto por (empresa, categoría).
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('collection_category_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('category', 100);
            $table->decimal('amount', 20, 2);
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_category_budgets');
    }
};
