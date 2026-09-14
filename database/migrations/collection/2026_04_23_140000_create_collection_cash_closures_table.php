<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre de caja diario del módulo Collection (Deuda & Abono).
 *
 * Cada fila representa el cierre de caja de un día específico para una empresa.
 * Guarda los totales snapshot del día (calculado) y lo declarado por el usuario,
 * junto con la diferencia (faltante/sobrante).
 *
 * Regla: se permite 1 cierre activo por (company_id, closure_date). Si se
 * "reabre" se conserva el registro con reopened_at/reopened_by y se crea uno
 * nuevo al volver a cerrar.
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE collection_cash_closures (
                id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                closure_date DATE NOT NULL,
                country_code VARCHAR(2),
                currency VARCHAR(10),
                user_id BIGINT NOT NULL,

                total_cobros NUMERIC(20, 2) NOT NULL DEFAULT 0,
                total_adiciones NUMERIC(20, 2) NOT NULL DEFAULT 0,
                total_ingresos_manuales NUMERIC(20, 2) NOT NULL DEFAULT 0,
                total_gastos NUMERIC(20, 2) NOT NULL DEFAULT 0,
                total_egresos_manuales NUMERIC(20, 2) NOT NULL DEFAULT 0,
                total_transferencias NUMERIC(20, 2) NOT NULL DEFAULT 0,
                esperado NUMERIC(20, 2) NOT NULL DEFAULT 0,

                efectivo_contado NUMERIC(20, 2) NOT NULL DEFAULT 0,
                transferencias_recibidas NUMERIC(20, 2) NOT NULL DEFAULT 0,
                total_declarado NUMERIC(20, 2) NOT NULL DEFAULT 0,
                faltante_sobrante NUMERIC(20, 2) NOT NULL DEFAULT 0,

                notas TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'closed',
                closed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                reopened_at TIMESTAMP WITH TIME ZONE,
                reopened_by BIGINT,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, company_id)
            ) PARTITION BY LIST (company_id);
        ");

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_cash_closures_company_date
                ON collection_cash_closures (company_id, closure_date DESC);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_cash_closures_status
                ON collection_cash_closures (company_id, status, closure_date);
        ');

        DB::connection($this->connection)->statement('
            CREATE SEQUENCE IF NOT EXISTS collection_cash_closures_id_seq;
        ');
        DB::connection($this->connection)->statement("
            ALTER TABLE collection_cash_closures
            ALTER COLUMN id SET DEFAULT nextval('collection_cash_closures_id_seq');
        ");
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_cash_closures');
        DB::connection($this->connection)->statement('DROP SEQUENCE IF EXISTS collection_cash_closures_id_seq;');
    }
};
