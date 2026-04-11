<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS collection_user_profiles (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                role VARCHAR(30) NOT NULL DEFAULT 'collector',
                permissions JSONB NOT NULL DEFAULT '[]',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, company_id)
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX idx_cup_company ON collection_user_profiles (company_id)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS collection_user_profiles");
    }
};
