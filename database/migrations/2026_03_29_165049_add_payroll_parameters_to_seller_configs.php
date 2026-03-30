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
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->string('payroll_frequency')->default('weekly')->after('weekly_allowance'); // daily, weekly, biweekly, monthly
            $table->integer('payroll_start_day')->default(1)->after('payroll_frequency'); // 1 = Monday, 7 = Sunday
            $table->boolean('include_sundays')->default(false)->after('payroll_start_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_configs', function (Blueprint $table) {
             $table->dropColumn(['payroll_frequency', 'payroll_start_day', 'include_sundays']);
        });
    }
};
