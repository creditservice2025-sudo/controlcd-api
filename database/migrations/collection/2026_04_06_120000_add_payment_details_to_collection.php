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
        // Add columns to collection_installments
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_installments 
            ADD COLUMN payment_method VARCHAR(50),
            ADD COLUMN notes TEXT;
        ');

        // Add columns to collection_payments
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_payments 
            ADD COLUMN payment_method VARCHAR(50),
            ADD COLUMN notes TEXT;
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_installments 
            DROP COLUMN payment_method,
            DROP COLUMN notes;
        ');

        DB::connection($this->connection)->statement('
            ALTER TABLE collection_payments 
            DROP COLUMN payment_method,
            DROP COLUMN notes;
        ');
    }
};
