<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALVAGUARDA GLOBAL: Asegura que todas las tablas del modulo Collection
 * tengan secuencias nativas de PostgreSQL vinculadas a su campo ID.
 * 
 * Este archivo reside en el directorio raiz de migraciones para asegurar
 * que se ejecute en despliegues estandar de staging/produccion, incluso
 * si se omiten las migraciones del subdirectorio 'collection'.
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
        'collection_client_audits',
        'collection_credit_audits',
    ];

    public function up(): void
    {
        // 1. Verificar si la conexion existe y es accesible
        try {
            $conn = DB::connection($this->connection);
            $conn->getPdo();
        } catch (\Exception $e) {
            // Si la conexion no existe (ej. ambiente sin PostgreSQL), saltar silenciosamente
            return;
        }

        foreach ($this->tables as $table) {
            // Verificar existencia de la tabla
            if (!Schema::connection($this->connection)->hasTable($table)) {
                continue;
            }

            $sequence = "{$table}_id_seq";

            // A. Crear secuencia si no existe
            $conn->statement("CREATE SEQUENCE IF NOT EXISTS {$sequence}");

            // B. Vincular ownership (limpieza automatica)
            try {
                $conn->statement("ALTER SEQUENCE {$sequence} OWNED BY {$table}.id");
            } catch (\Throwable $e) {}

            // C. Sincronizar secuencia con el maximo ID actual para evitar colisiones
            $conn->statement(
                "SELECT setval('{$sequence}', COALESCE((SELECT MAX(id) FROM {$table}), 0) + 1, false)"
            );

            // D. Establecer el valor por defecto del campo ID
            // Esto es lo que evita el error "null value in column id"
            $conn->statement(
                "ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT nextval('{$sequence}')"
            );
        }
    }

    public function down(): void
    {
        // No revertimos por seguridad (las secuencias son mejoras de infraestructura)
    }
};
