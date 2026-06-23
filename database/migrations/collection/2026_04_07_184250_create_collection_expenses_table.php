<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('collection_pgsql')->create('collection_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->decimal('amount', 15, 2);
            $table->string('description', 500)->nullable();
            $table->string('category', 50)->nullable(); // fuel, food, toll, maintenance, other
            $table->string('status', 20)->default('approved'); 
            $table->timestamp('recorded_at')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->jsonb('metadata')->nullable(); 
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('collection_pgsql')->dropIfExists('collection_expenses');
    }
};
