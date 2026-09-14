<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla particionada por company_id que registra adiciones de capital a creditos
 * existentes en Collection (modo monthly_interest_open).
 *
 * Cada fila = desembolso adicional al cliente sobre un credito activo.
 * Impacto al crear una fila:
 *   1) INSERT aqui
 *   2) UPDATE collection_credits SET amount = amount + {monto}
 *   3) Wallet movement tipo debit (action_type=capital_addition)
 *
 * La cuota mensual NO se recalcula aqui — se recalcula naturalmente cuando
 * generateInstallments() emita la proxima cuota tras el pago de la vigente.
 */
return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE collection_capital_additions (
                id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                credit_id BIGINT NOT NULL,
                amount NUMERIC(20, 2) NOT NULL,
                business_date DATE NOT NULL,
                payment_method VARCHAR(50),
                reference_number VARCHAR(100),
                bank_name VARCHAR(100),
                voucher_photo VARCHAR(255),
                notes TEXT,
                created_by BIGINT,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, company_id)
            ) PARTITION BY LIST (company_id);
        ");

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_capital_credit ON collection_capital_additions (company_id, credit_id);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_capital_business_date_brin ON collection_capital_additions USING brin (business_date);
        ');

        // Crear secuencia para generar IDs (mismo patron que otras tablas collection).
        DB::connection($this->connection)->statement('
            CREATE SEQUENCE IF NOT EXISTS collection_capital_additions_id_seq;
        ');
        DB::connection($this->connection)->statement("
            ALTER TABLE collection_capital_additions
            ALTER COLUMN id SET DEFAULT nextval('collection_capital_additions_id_seq');
        ");
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_capital_additions');
        DB::connection($this->connection)->statement('DROP SEQUENCE IF EXISTS collection_capital_additions_id_seq;');
    }
};
