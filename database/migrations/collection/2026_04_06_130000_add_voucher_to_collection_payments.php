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
        // Add voucher_path to collection_payments and installments
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_payments 
            ADD COLUMN voucher_path VARCHAR(255);
        ');

        DB::connection($this->connection)->statement('
            ALTER TABLE collection_installments 
            ADD COLUMN voucher_path VARCHAR(255);
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE collection_payments 
            DROP COLUMN voucher_path;
        ');

        DB::connection($this->connection)->statement('
            ALTER TABLE collection_installments 
            DROP COLUMN voucher_path;
        ');
    }
};
