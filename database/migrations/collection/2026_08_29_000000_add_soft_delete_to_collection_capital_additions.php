<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Baja logica de adiciones de capital.
 *
 * Una adicion NO se borra fisicamente: se marca. El motivo es que la adicion
 * ya movio dos cosas (el `amount` del credito y la caja), y el historial tiene
 * que poder explicar por que el saldo bajo. Un DELETE dejaria la caja con un
 * movimiento huerfano y sin quien lo justifique.
 *
 * La tabla esta particionada por company_id; ALTER TABLE sobre el padre baja
 * sola a todas las particiones.
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        $conn->statement('
            ALTER TABLE collection_capital_additions
            ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP WITH TIME ZONE NULL,
            ADD COLUMN IF NOT EXISTS deleted_by BIGINT NULL,
            ADD COLUMN IF NOT EXISTS deletion_reason TEXT NULL;
        ');

        // Las consultas vivas siempre filtran deleted_at IS NULL; el indice
        // parcial las mantiene baratas sin pesar por las filas dadas de baja.
        $conn->statement('
            CREATE INDEX IF NOT EXISTS idx_col_capital_alive
            ON collection_capital_additions (company_id, credit_id)
            WHERE deleted_at IS NULL;
        ');
    }

    public function down(): void
    {
        $conn = DB::connection($this->connection);

        $conn->statement('DROP INDEX IF EXISTS idx_col_capital_alive;');
        $conn->statement('
            ALTER TABLE collection_capital_additions
            DROP COLUMN IF EXISTS deleted_at,
            DROP COLUMN IF EXISTS deleted_by,
            DROP COLUMN IF EXISTS deletion_reason;
        ');
    }
};
