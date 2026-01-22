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
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('client_timezone')->nullable()->after('status');
            $table->timestamp('business_timestamp')->nullable()->after('client_timezone');
            $table->date('business_date')->nullable()->after('business_timestamp');
            $table->string('business_timezone')->nullable()->after('business_date');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->string('client_timezone')->nullable()->after('description');
            $table->timestamp('business_timestamp')->nullable()->after('client_timezone');
            $table->date('business_date')->nullable()->after('business_timestamp');
            $table->string('business_timezone')->nullable()->after('business_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['client_timezone', 'business_timestamp', 'business_date', 'business_timezone']);
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn(['client_timezone', 'business_timestamp', 'business_date', 'business_timezone']);
        });
    }
};
