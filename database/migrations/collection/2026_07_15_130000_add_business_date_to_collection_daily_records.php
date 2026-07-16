<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fecha contable (business_date) para los registros diarios de Collection.
 *
 * Hasta ahora el día contable se derivaba en tiempo de consulta con
 * "recorded_at AT TIME ZONE tz", lo cual es incorrecto porque recorded_at es
 * un TIMESTAMP sin zona (AT TIME ZONE solo funciona bien sobre timestamptz).
 *
 * business_date congela el día calendario en la zona del PAÍS del movimiento
 * (country_code → IANA, ver TimezoneHelper::timezoneForCountryCode) al momento
 * de registrar. Es inmutable y todos los reportes/cortes agrupan por esta
 * columna, sin depender de conversiones de zona en cada lectura.
 *
 * Mismo patrón que collection_credits y collection_capital_additions.
 *
 * Se puebla al escribir (CollectionDailyRecordService) y para históricos con
 * el comando `collection:backfill-business-dates`.
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
            'ALTER TABLE collection_daily_records ADD COLUMN IF NOT EXISTS business_date DATE'
        );

        // Índice compuesto para las consultas reales del módulo:
        //   WHERE company_id = ? AND business_date = ?         (corte/listado)
        //   WHERE company_id = ? AND business_date BETWEEN ...  (tendencia)
        // La tabla NO está particionada, así que un btree compuesto sirve mejor
        // que un BRIN para estos accesos por empresa+día.
        DB::connection($this->connection)->statement(
            'CREATE INDEX IF NOT EXISTS idx_col_daily_records_company_business_date
                ON collection_daily_records (company_id, business_date)'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP INDEX IF EXISTS idx_col_daily_records_company_business_date'
        );
        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_daily_records DROP COLUMN IF EXISTS business_date'
        );
    }
};
