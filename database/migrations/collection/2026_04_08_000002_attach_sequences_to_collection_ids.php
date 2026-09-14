<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reemplaza la generación manual de IDs (`max('id') + 1`) en el módulo
 * Collection por secuencias nativas de PostgreSQL.
 *
 * Motivación: el patrón `max + 1` calculado en PHP es vulnerable a
 * race conditions bajo concurrencia → IDs duplicados → violación de PK.
 * Las secuencias de PostgreSQL son atómicas y monotónicas.
 *
 * Para tablas particionadas (collection_payments), la secuencia se aplica
 * en la tabla padre y todas las particiones la heredan automáticamente.
 *
 * Esta migración es idempotente: usa CREATE SEQUENCE IF NOT EXISTS y
 * setval() ajusta la posición a max(id)+1 actual para no colisionar
 * con los IDs ya asignados manualmente.
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    private array $tables = [
        'collection_wallets',
        'collection_ledger',
        'collection_credits',
        'collection_installments',
        'collection_payments',
        'collection_clients',
        'collection_auth_codes',
        'collection_expenses',
    ];

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        foreach ($this->tables as $table) {
            // Si la tabla no existe en este ambiente, saltarla.
            $exists = $conn->selectOne(
                "SELECT to_regclass(?) AS rel",
                [$table]
            );
            if (!$exists || $exists->rel === null) {
                continue;
            }

            $sequence = "{$table}_id_seq";

            // 1. Crear secuencia si no existe.
            $conn->statement("CREATE SEQUENCE IF NOT EXISTS {$sequence}");

            // 2. Vincular ownership al campo id (limpieza al hacer DROP TABLE).
            //    Se hace en bloque try porque si la tabla está particionada,
            //    OWNED BY no aplica de la misma forma — lo intentamos suave.
            try {
                $conn->statement("ALTER SEQUENCE {$sequence} OWNED BY {$table}.id");
            } catch (\Throwable $e) {
                // Ignorar: algunas configuraciones de partición no aceptan OWNED BY.
            }

            // 3. Posicionar la secuencia al max(id) actual + 1, sin colisionar.
            //    Si la tabla está vacía, arrancar en 1.
            $conn->statement(
                "SELECT setval('{$sequence}', COALESCE((SELECT MAX(id) FROM {$table}), 0) + 1, false)"
            );

            // 4. Establecer el default del campo id para que use la secuencia.
            $conn->statement(
                "ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT nextval('{$sequence}')"
            );
        }
    }

    public function down(): void
    {
        $conn = DB::connection($this->connection);

        foreach ($this->tables as $table) {
            $exists = $conn->selectOne("SELECT to_regclass(?) AS rel", [$table]);
            if (!$exists || $exists->rel === null) {
                continue;
            }

            $sequence = "{$table}_id_seq";

            try {
                $conn->statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
            } catch (\Throwable $e) {
            }
            try {
                $conn->statement("DROP SEQUENCE IF EXISTS {$sequence}");
            } catch (\Throwable $e) {
            }
        }
    }
};
