<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL for partitioning as Laravel Schema doesn't support it natively well for PG
        DB::connection($this->connection)->statement('
            CREATE TABLE collection_clients (
                id BIGINT NOT NULL,
                company_id BIGINT NOT NULL,
                dni VARCHAR(20) NOT NULL,
                name VARCHAR(255) NOT NULL,
                phone VARCHAR(20),
                address TEXT,
                metadata JSONB,
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, company_id)
            ) PARTITION BY LIST (company_id);
        ');

        // Create initial default partition or indices
        // Note: Specific company partitions will be created dynamically or via DevOps script
        // For now, let's add a "template" or "default" partition if needed, 
        // but typically partitions are created per tenant ID.
        
        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_clients_company_dni ON collection_clients (company_id, dni);
        ');
        
        DB::connection($this->connection)->statement('
            CREATE INDEX idx_col_clients_name_gin ON collection_clients USING gin (name gin_trgm_ops);
        -- Requires pg_trgm extension for fast name search
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_clients');
    }
};
