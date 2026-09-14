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
            ALTER TABLE collection_installments 
            ADD COLUMN principal_amount NUMERIC(20, 2) DEFAULT 0,
            ADD COLUMN interest_amount NUMERIC(20, 2) DEFAULT 0,
            ADD COLUMN principal_paid NUMERIC(20, 2) DEFAULT 0,
            ADD COLUMN interest_paid NUMERIC(20, 2) DEFAULT 0;
        ');
        
        // Update existing records (Principal = Amount - 0, Interest = 0 for legacy)
        // Or we can try to infer it if we want, but legacy is legacy.
        DB::connection($this->connection)->statement('
            UPDATE collection_installments SET principal_amount = amount;
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_installments 
            DROP COLUMN principal_amount,
            DROP COLUMN interest_amount,
            DROP COLUMN principal_paid,
            DROP COLUMN interest_paid;
        ');
    }
};
