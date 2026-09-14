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
        Schema::connection($this->connection)->table('collection_installments', function (Blueprint $table) {
            $table->timestampTz('deleted_at')->nullable();
            $table->bigInteger('deleted_by')->nullable();
            $table->string('deleted_ip', 45)->nullable();
            $table->jsonb('history')->nullable();
            
            // For record keeping
            $table->index(['company_id', 'deleted_at'], 'idx_col_installments_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('collection_installments', function (Blueprint $table) {
            $table->dropColumn(['deleted_at', 'deleted_by', 'deleted_ip', 'history']);
        });
    }
};
