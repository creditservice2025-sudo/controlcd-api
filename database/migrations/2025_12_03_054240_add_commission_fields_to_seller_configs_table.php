<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->string('commission_paid_credits_type')->default('percentage')->after('commission_paid_credits');
            $table->decimal('monthly_savings', 10, 2)->default(0)->after('monthly_fixed_salary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->dropColumn(['commission_paid_credits_type', 'monthly_savings']);
        });
    }
};
