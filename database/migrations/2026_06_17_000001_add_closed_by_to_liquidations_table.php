<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de "quién realizó la liquidación".
 *
 * Hasta ahora el autor del cierre solo quedaba en liquidation_audits. Con el
 * flujo de supervisor (rol 6) que también puede cerrar la caja de un vendedor,
 * necesitamos saber directamente quién la cerró (vendedor o supervisor) sin
 * tener que reconstruirlo desde la auditoría.
 *
 *  - closed_by:      user_id de quien ejecutó el cierre (Auth::user()).
 *  - closed_by_role: role_id de ese usuario (5 = vendedor, 6 = supervisor, ...).
 *  - closed_at:      momento del cierre en la zona horaria del vendedor.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            if (!Schema::hasColumn('liquidations', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('status');
                $table->index('closed_by');
            }
            if (!Schema::hasColumn('liquidations', 'closed_by_role')) {
                $table->unsignedTinyInteger('closed_by_role')->nullable()->after('closed_by');
            }
            if (!Schema::hasColumn('liquidations', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('closed_by_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            if (Schema::hasColumn('liquidations', 'closed_by')) {
                $table->dropIndex(['closed_by']);
                $table->dropColumn('closed_by');
            }
            if (Schema::hasColumn('liquidations', 'closed_by_role')) {
                $table->dropColumn('closed_by_role');
            }
            if (Schema::hasColumn('liquidations', 'closed_at')) {
                $table->dropColumn('closed_at');
            }
        });
    }
};
