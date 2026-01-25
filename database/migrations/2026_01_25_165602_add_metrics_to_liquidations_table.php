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
        Schema::table('liquidations', function (Blueprint $table) {
            $table->integer('clients_paid_count')->default(0)->after('total_income');
            $table->integer('clients_without_credit_count')->default(0)->after('clients_paid_count');
            $table->integer('new_clients_count')->default(0)->after('clients_without_credit_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn(['clients_paid_count', 'clients_without_credit_count', 'new_clients_count']);
        });
    }
};
