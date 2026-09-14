<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS collection_reminders (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL,
                installment_id BIGINT NOT NULL,
                credit_id BIGINT NOT NULL,
                client_id BIGINT NOT NULL,
                channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
                sent_by_user_id BIGINT NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX idx_cr_company_sent ON collection_reminders (company_id, sent_at DESC)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX idx_cr_installment ON collection_reminders (installment_id, company_id)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS collection_reminders");
    }
};
