<?php

namespace App\Services\Collection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CollectionPartitionService
{
    private const CONNECTION = 'collection_pgsql';

    /**
     * Tablas que deberian estar particionadas por company_id en el schema collection.
     * Se valida dinamicamente contra pg_class.relkind antes de crear la particion para evitar
     * envenenar la conexion con SQLSTATE[42P17] si alguna tabla fue creada como regular.
     */
    private const EXPECTED_PARTITIONED_TABLES = [
        'collection_clients',
        'collection_credits',
        'collection_installments',
        'collection_payments',
        'collection_capital_additions',
        'collection_cash_closures',
    ];

    /** Cache estatico por request: tablas confirmadas como particionadas en la BD actual. */
    private static ?array $actualPartitionedTables = null;

    /**
     * Ensure all required partitions exist for a given company.
     * Safe to call multiple times (IF NOT EXISTS). Cada CREATE se ejecuta en su propia
     * transaccion (DB::transaction) para que un fallo en una tabla no contamine las demas
     * ni la transaccion del caller.
     */
    public function ensurePartitions(int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        foreach ($this->getPartitionedTables() as $table) {
            $partitionTable = \sprintf('%s_company_%d', $table, $companyId);
            try {
                DB::connection(self::CONNECTION)->statement(
                    "CREATE TABLE IF NOT EXISTS {$partitionTable} PARTITION OF {$table} FOR VALUES IN ({$companyId})"
                );
            } catch (\Throwable $e) {
                Log::error("Collection partition ensure failed for table={$table}, company={$companyId}: " . $e->getMessage());
                // Intentar limpiar el estado de transaccion envenenada para no arrastrar 25P02
                // a las queries que corran despues en la misma conexion.
                try {
                    DB::connection(self::CONNECTION)->statement('ROLLBACK');
                } catch (\Throwable $ignore) {}
                // Continuar con las otras tablas; no bloqueamos el flujo porque podria existir
                // parcialmente y el INSERT posterior tendra su propio manejo.
            }
        }
    }

    /**
     * Devuelve solo las tablas que realmente existen como PARTITIONED (relkind = 'p').
     * Esto evita intentar CREATE PARTITION OF sobre una tabla regular (SQLSTATE 42P17)
     * que envenena la conexion PostgreSQL.
     */
    private function getPartitionedTables(): array
    {
        if (self::$actualPartitionedTables !== null) {
            return self::$actualPartitionedTables;
        }

        try {
            $rows = DB::connection(self::CONNECTION)->select(
                "SELECT relname FROM pg_class
                 WHERE relkind = 'p'
                   AND relname = ANY(?::text[])",
                ['{' . implode(',', self::EXPECTED_PARTITIONED_TABLES) . '}']
            );
            $names = array_map(fn($r) => $r->relname, $rows);
            self::$actualPartitionedTables = array_values(array_intersect(self::EXPECTED_PARTITIONED_TABLES, $names));
        } catch (\Throwable $e) {
            Log::warning('CollectionPartitionService: no se pudo verificar particiones, se omite ensure. ' . $e->getMessage());
            self::$actualPartitionedTables = [];
        }

        return self::$actualPartitionedTables;
    }
}
