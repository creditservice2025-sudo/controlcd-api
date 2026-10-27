<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas de recaudo: además de la meta GENERAL de una moneda (seller_id null),
 * una meta PROPIA para un vendedor puntual (seller_id = X). La del vendedor
 * manda sobre la general de su moneda; sin meta propia se usa la general.
 *
 * Idempotente (Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_collection_scales', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_collection_scales', 'seller_id')) {
                $table->unsignedBigInteger('seller_id')->nullable()->after('currency');
                $table->index(['company_id', 'seller_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_collection_scales', function (Blueprint $table) {
            if (Schema::hasColumn('seller_collection_scales', 'seller_id')) {
                $table->dropIndex(['company_id', 'seller_id']);
                $table->dropColumn('seller_id');
            }
        });
    }
};
