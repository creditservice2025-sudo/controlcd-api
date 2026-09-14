<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasColumn('collection_clients', 'country_code')) {
            Schema::connection($this->connection)->table('collection_clients', function (Blueprint $table) {
                $table->string('country_code', 5)->nullable()->after('address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('collection_clients', 'country_code')) {
            Schema::connection($this->connection)->table('collection_clients', function (Blueprint $table) {
                $table->dropColumn('country_code');
            });
        }
    }
};
