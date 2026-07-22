<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la EVIDENCIA (comprobante) en ediciones: guarda las rutas de
 * la imagen anterior y la nueva. La imagen anterior NUNCA se borra del disco;
 * queda referenciada aquí para poder verla en el histórico (auditoría inmutable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->table('collection_daily_record_audits', function (Blueprint $table) {
            $table->jsonb('old_evidence')->nullable();
            $table->jsonb('new_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->table('collection_daily_record_audits', function (Blueprint $table) {
            $table->dropColumn(['old_evidence', 'new_evidence']);
        });
    }
};
