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
        DB::connection($this->connection)->statement("
            CREATE TABLE collection_credits (
                id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                amount NUMERIC(20, 2) NOT NULL,
                interest_rate NUMERIC(10, 2) NOT NULL,
                total_installments INT NOT NULL,
                payment_frequency VARCHAR(50),
                first_installment_date DATE,
                status VARCHAR(50) DEFAULT 'active',
                business_date DATE NOT NULL,
                metadata JSONB,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, company_id)
            ) PARTITION BY LIST (company_id);
        ");

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_credits_company_client_status ON collection_credits (company_id, client_id, status);
        ');

        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_credits_business_date_brin ON collection_credits USING brin (business_date);
        ');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_credits');
    }
};
