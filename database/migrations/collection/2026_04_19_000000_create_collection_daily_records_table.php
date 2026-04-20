<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('collection_pgsql')->create('collection_daily_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            // ingreso | gasto | transferencia | ajuste
            $table->string('type', 20)->index();
            $table->string('category', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('COP');
            $table->string('country_code', 2)->nullable()->index();
            $table->string('description', 500)->nullable();
            $table->timestamp('recorded_at')->nullable()->index();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'recorded_at']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('collection_pgsql')->dropIfExists('collection_daily_records');
    }
};
