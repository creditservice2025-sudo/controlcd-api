<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_collection';

    public function up(): void
    {
        DB::connection($this->connection)->statement('
            CREATE TABLE collection_payments (
                id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                credit_id BIGINT NOT NULL,
                installment_number INT NOT NULL,
                amount_paid NUMERIC(20, 2) NOT NULL,
                payment_date DATE NOT NULL,
                recorded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                receipt_number VARCHAR(100),
                PRIMARY KEY (id, company_id)
            ) PARTITION BY LIST (company_id);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_payments_credit_id ON collection_payments (company_id, credit_id);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_payments_payment_date_brin ON collection_payments USING brin (payment_date);
        -- Faster range scans for high volume daily abonos
        ');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_payments');
    }
};
