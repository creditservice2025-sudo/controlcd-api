<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mostrar avance al vendedor": la barra de progreso hacia su meta de recaudo
 * en la pantalla del cobrador. La decide SOLO el Super-Admin, por empresa.
 *
 *  - companies.show_collection_progress: APAGADO por defecto. Ninguna empresa
 *    ve nada nuevo hasta que el Super-Admin lo prenda.
 *  - collection_progress_audits: quién lo prendió o apagó y cuándo.
 *
 * Solo agrega una columna y una tabla: no toca ningún dato existente.
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'show_collection_progress')) {
                $table->boolean('show_collection_progress')->default(false);
            }
        });

        if (!Schema::hasTable('collection_progress_audits')) {
            Schema::create('collection_progress_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->boolean('enabled');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 191)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['company_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_progress_audits');
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'show_collection_progress')) {
                $table->dropColumn('show_collection_progress');
            }
        });
    }
};
