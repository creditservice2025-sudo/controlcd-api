<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement('
            CREATE TABLE collection_installments (
                id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                credit_id BIGINT NOT NULL,
                installment_number INT NOT NULL,
                due_date DATE NOT NULL,
                amount NUMERIC(20, 2) NOT NULL,
                paid_amount NUMERIC(20, 2) DEFAULT 0,
                status VARCHAR(20) DEFAULT \'pendiente\',
                last_payment_at TIMESTAMP WITH TIME ZONE,
                recorded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, company_id)
            ) PARTITION BY LIST (company_id);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_installments_credit_id ON collection_installments (company_id, credit_id);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_installments_due_date_brin ON collection_installments USING brin (due_date);
        ');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_installments');
    }
};
