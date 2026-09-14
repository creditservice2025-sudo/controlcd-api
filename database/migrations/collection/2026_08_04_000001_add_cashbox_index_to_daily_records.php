<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice compuesto (company_id, cashbox_id) sobre los registros diarios.
 *
 * El saldo por caja NO se persiste: se deriva agregando todos los movimientos
 * históricos de la empresa (`CollectionCashboxService::balancesFor`), y esa
 * agregación se dispara cada vez que se refresca la lista de cajas. Hoy los
 * únicos índices útiles son `(company_id)` y `(cashbox_id)` por separado: el
 * planificador filtra por empresa y recién ahí agrupa, así que el costo crece
 * con TODO el histórico de la empresa.
 *
 * Con el compuesto, el filtro por empresa y el GROUP BY por caja se resuelven
 * en el mismo recorrido de índice.
 *
 * CONCURRENTLY para no bloquear escrituras en producción; por eso la migración
 * NO puede correr dentro de una transacción (`$withinTransaction = false`).
 *
 * Ejecutar con:
 *   php artisan migrate --database=collection_pgsql --path=database/migrations/collection/2026_08_04_000001_add_cashbox_index_to_daily_records.php
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_col_daily_records_company_cashbox
             ON collection_daily_records (company_id, cashbox_id)'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP INDEX CONCURRENTLY IF EXISTS idx_col_daily_records_company_cashbox'
        );
    }
};
