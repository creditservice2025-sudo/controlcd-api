<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'collection_pgsql';

    public function up(): void
    {
        Schema::connection($this->connection)->create('collection_auth_codes', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('company_id');
            $table->string('request_id')->index();
            $table->string('code', 10);
            $table->string('action_type', 50)->default('delete_payment');
            $table->jsonb('metadata')->nullable();
            $table->string('status', 20)->default('pending'); // pending, used, expired
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            
            // Partition support
            $table->index(['company_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('collection_auth_codes');
    }
};
