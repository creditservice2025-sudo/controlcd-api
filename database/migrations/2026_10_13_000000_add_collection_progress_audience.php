<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mostrar avance al vendedor": QUIÉN ve la barra.
 *
 *  - companies.collection_progress_scope: 'all' (todas las rutas, default: lo
 *    que ya estaba prendido sigue igual) o 'custom' (audiencia elegida).
 *  - collection_progress_targets: la audiencia de 'custom'. Rutas (city) y
 *    vendedores (seller) se SUMAN: una ruta incluye a todos sus vendedores,
 *    también a los que se agreguen después; un vendedor se incluye solo.
 *  - collection_progress_audits.detail: la audiencia que quedó en cada cambio,
 *    para el historial.
 *
 * Solo agrega: no toca datos existentes. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'collection_progress_scope')) {
                $table->string('collection_progress_scope', 10)->default('all');
            }
        });

        if (!Schema::hasTable('collection_progress_targets')) {
            Schema::create('collection_progress_targets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('target_type', 10); // city | seller
                $table->unsignedBigInteger('target_id');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['company_id', 'target_type', 'target_id'], 'cpt_company_type_target_unique');
            });
        }

        Schema::table('collection_progress_audits', function (Blueprint $table) {
            if (!Schema::hasColumn('collection_progress_audits', 'detail')) {
                $table->json('detail')->nullable()->after('enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('collection_progress_audits', function (Blueprint $table) {
            if (Schema::hasColumn('collection_progress_audits', 'detail')) {
                $table->dropColumn('detail');
            }
        });
        Schema::dropIfExists('collection_progress_targets');
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'collection_progress_scope')) {
                $table->dropColumn('collection_progress_scope');
            }
        });
    }
};
