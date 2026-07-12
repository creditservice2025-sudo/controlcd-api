<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo híbrido de feriados:
 *   - company_id NULL  -> feriado NACIONAL (compartido por todas las empresas
 *                         del país). Solo lo administra el Super-Admin.
 *   - company_id = X   -> feriado propio de la empresa X.
 *
 * Originalmente company_id era NOT NULL (feriados solo por empresa). Aquí se
 * vuelve nullable para permitir el calendario nacional compartido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('holidays')) {
            return;
        }

        // Quitar la FK para poder alterar la nulabilidad de la columna.
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('holidays')) {
            return;
        }

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }
};
