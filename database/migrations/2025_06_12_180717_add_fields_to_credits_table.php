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
        Schema::table('credits', function (Blueprint $table) {
            $table->json('excluded_days')->nullable()->after('payment_frequency');
            $table->decimal('micro_insurance_percentage', 5, 2)->nullable()->after('excluded_days');
            $table->decimal('micro_insurance_amount', 10, 2)->nullable()->after('micro_insurance_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            //
        });
    }
};
