<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS collection_company_configs (
                id BIGSERIAL PRIMARY KEY,
                company_id BIGINT NOT NULL UNIQUE,
                currencies JSONB NOT NULL DEFAULT '[{\"currency\":\"COP\",\"country_code\":\"CO\"}]',
                default_currency VARCHAR(10) NOT NULL DEFAULT 'COP',
                default_country_code VARCHAR(5) NOT NULL DEFAULT 'CO',
                timezone VARCHAR(50) DEFAULT 'America/Bogota',
                settings JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS collection_company_configs");
    }
};
