<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de borrado en registros diarios de Collection.
 *
 * La tabla ya usa softDeletes (deleted_at). Se agrega deleted_by para saber
 * QUIÉN eliminó cada movimiento y poder mostrarlo en el trail ("Eliminado por
 * X · fecha hora"). Se llena a partir de ahora en CollectionDailyRecordService::
 * destroy(); los borrados históricos quedan con deleted_by NULL (desconocido).
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_daily_records ADD COLUMN IF NOT EXISTS deleted_by BIGINT'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_daily_records DROP COLUMN IF EXISTS deleted_by'
        );
    }
};
