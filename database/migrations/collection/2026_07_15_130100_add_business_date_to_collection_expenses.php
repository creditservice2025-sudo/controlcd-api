<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fecha contable (business_date) para los gastos de Collection.
 *
 * Igual que collection_daily_records: el día contable se derivaba con
 * "recorded_at AT TIME ZONE tz", incorrecto porque recorded_at es TIMESTAMP
 * sin zona. Peor aún, los gastos guardan recorded_at con Carbon::now() (zona
 * de la app, America/Lima), no en UTC.
 *
 * Los gastos NO tienen country_code (son a nivel empresa), así que business_date
 * se ancla a la zona de la EMPRESA (companies.timezone) al registrar. Inmutable
 * y usado por todos los cortes/reportes.
 *
 * Se puebla al escribir (CollectionExpenseService) y para históricos con
 * `collection:backfill-business-dates`.
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
            'ALTER TABLE collection_expenses ADD COLUMN IF NOT EXISTS business_date DATE'
        );

        // btree compuesto (company_id, business_date): la tabla no está
        // particionada y las consultas filtran por empresa + día contable.
        DB::connection($this->connection)->statement(
            'CREATE INDEX IF NOT EXISTS idx_col_expenses_company_business_date
                ON collection_expenses (company_id, business_date)'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP INDEX IF EXISTS idx_col_expenses_company_business_date'
        );
        DB::connection($this->connection)->statement(
            'ALTER TABLE collection_expenses DROP COLUMN IF EXISTS business_date'
        );
    }
};
