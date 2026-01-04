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
        Schema::create('company_holiday', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('holiday_id')->constrained()->onDelete('cascade');
            $table->boolean('is_working')->default(true); // true = works, false = does not work (override)
            $table->timestamps();

            $table->unique(['company_id', 'holiday_id']);
        });

        Schema::create('seller_holiday', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->onDelete('cascade');
            $table->foreignId('holiday_id')->constrained()->onDelete('cascade');
             $table->boolean('is_working')->default(true);
            $table->timestamps();

            $table->unique(['seller_id', 'holiday_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_holiday');
        Schema::dropIfExists('company_holiday');
    }
};
