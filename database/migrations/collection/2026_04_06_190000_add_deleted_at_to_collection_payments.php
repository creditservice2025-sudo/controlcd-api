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
        Schema::connection($this->connection)->table('collection_payments', function (Blueprint $table) {
            $table->softDeletesTz(); // Adds deleted_at with timezone
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('collection_payments', function (Blueprint $table) {
            $table->dropSoftDeletesTz();
        });
    }
};
