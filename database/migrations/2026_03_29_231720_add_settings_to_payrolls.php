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
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('payroll_frequency')->nullable()->after('net_total');
            $table->integer('payroll_start_day')->nullable()->after('payroll_frequency');
            $table->boolean('include_sundays')->default(false)->after('payroll_start_day');
            $table->unsignedBigInteger('updated_by_id')->nullable()->after('include_sundays');
            
            $table->foreign('updated_by_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropForeign(['updated_by_id']);
            $table->dropColumn(['payroll_frequency', 'payroll_start_day', 'include_sundays', 'updated_by_id']);
        });
    }
};
