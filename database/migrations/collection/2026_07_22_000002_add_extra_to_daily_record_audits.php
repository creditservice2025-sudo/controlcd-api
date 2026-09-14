<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo genérico para auditar OTROS cambios del movimiento (fecha/hora
 * recorded_at, destino transfer_to, etc.) sin agregar una columna por cada uno.
 * Formato: { "recorded_at": {"old":..,"new":..}, "transfer_to": {"old":..,"new":..} }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->table('collection_daily_record_audits', function (Blueprint $table) {
            $table->jsonb('extra')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->table('collection_daily_record_audits', function (Blueprint $table) {
            $table->dropColumn('extra');
        });
    }
};
