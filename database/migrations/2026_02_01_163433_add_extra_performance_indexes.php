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
        Schema::table('installments', function (Blueprint $table) {
            $table->index(['credit_id', 'status'], 'installments_credit_status_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'expenses_user_date_index');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'incomes_user_date_index');
        });

        Schema::table('liquidations', function (Blueprint $table) {
            $table->index(['seller_id', 'date'], 'liquidations_seller_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex('installments_credit_status_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_user_date_index');
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropIndex('incomes_user_date_index');
        });

        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropIndex('liquidations_seller_date_index');
        });
    }
};
