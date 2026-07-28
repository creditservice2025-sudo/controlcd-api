<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nombre de la ruta (route_name) y descripción (description) para los créditos
 * de Collection (Deuda & Abono).
 *
 * Ambos son obligatorios a nivel de API (ver CollectionCreditController::store),
 * pero se agregan como NULLABLE en la base para NO romper los créditos que ya
 * existen sin estos datos. La obligatoriedad se enforce en la capa de
 * validación; los históricos quedan con NULL hasta que se editen.
 *
 * collection_credits está PARTICIONADA por LIST (company_id). En PostgreSQL,
 * un ADD COLUMN sobre la tabla particionada padre se propaga automáticamente a
 * todas las particiones existentes y futuras, así que un solo ALTER basta.
 *
 * Mismo patrón que business_date (ver
 * 2026_07_15_130000_add_business_date_to_collection_daily_records).
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
            'ALTER TABLE collection_credits ADD COLUMN IF NOT EXISTS route_name VARCHAR(150)'
        );

        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_credits ADD COLUMN IF NOT EXISTS description TEXT'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_credits DROP COLUMN IF EXISTS description'
        );
        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_credits DROP COLUMN IF EXISTS route_name'
        );
    }
};
