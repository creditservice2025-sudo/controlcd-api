<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata cada registro diario a una caja (collection_cashboxes).
 * Nullable para no romper históricos: el backfill posterior asigna todos
 * los movimientos existentes a la "Caja principal" de cada empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->table('collection_daily_records', function (Blueprint $table) {
            $table->unsignedBigInteger('cashbox_id')->nullable()->index()->after('company_id');
            // Para transferencia: caja destino (caja->caja). Fase 2 lo explota;
            // se agrega ya para no re-migrar la tabla más adelante.
            $table->unsignedBigInteger('cashbox_to_id')->nullable()->after('cashbox_id');
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->table('collection_daily_records', function (Blueprint $table) {
            $table->dropColumn(['cashbox_id', 'cashbox_to_id']);
        });
    }
};
