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
        // En PostgreSQL, al añadir columnas a una tabla padre (PARTITION BY),
        // estas se propagan automaticamente a todas sus particiones.
        Schema::connection($this->connection)->table('collection_payments', function (Blueprint $table) {
            $table->decimal('interest_paid', 20, 2)->default(0)->after('amount_paid');
            $table->decimal('principal_paid', 20, 2)->default(0)->after('interest_paid');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('collection_payments', function (Blueprint $table) {
            $table->dropColumn(['interest_paid', 'principal_paid']);
        });
    }
};
