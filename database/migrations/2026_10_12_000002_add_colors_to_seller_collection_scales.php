<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas de recaudo: paleta de colores propia por meta. `colors` guarda el color
 * de cada tramo como {"rojo": "#C62828", "amarillo": ..., "azul": ...,
 * "verde": ...}; las claves son la POSICIÓN del tramo (1° a 4°), no el color.
 * null = la paleta de siempre (App\Support\EscalaRecaudo::COLORES).
 *
 * Idempotente (Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_collection_scales', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_collection_scales', 'colors')) {
                $table->json('colors')->nullable()->after('cut_blue');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_collection_scales', function (Blueprint $table) {
            if (Schema::hasColumn('seller_collection_scales', 'colors')) {
                $table->dropColumn('colors');
            }
        });
    }
};
