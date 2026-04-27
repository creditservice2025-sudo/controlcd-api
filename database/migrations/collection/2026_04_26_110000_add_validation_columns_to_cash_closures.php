<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega columnas validated_by + validated_at a collection_cash_closures
 * para soportar el flujo de auto_pending → admin valida → closed.
 *
 * El status puede ser: closed | reopened | auto_pending.
 * - auto_pending: cierre generado automáticamente por el cron (sin conteo físico).
 * - cuando admin lo valida → status = closed, validated_by/validated_at se rellenan.
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_cash_closures
            ADD COLUMN IF NOT EXISTS validated_by BIGINT NULL,
            ADD COLUMN IF NOT EXISTS validated_at TIMESTAMP WITH TIME ZONE NULL;
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_cash_closures
            DROP COLUMN IF EXISTS validated_by,
            DROP COLUMN IF EXISTS validated_at;
        ');
    }
};
