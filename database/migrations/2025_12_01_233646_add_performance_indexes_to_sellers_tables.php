<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * This migration adds strategic indexes to optimize the sellers view performance.
     * Expected improvement: 5-10x faster queries.
     */
    public function up(): void
    {
        // Indexes for sellers table
        Schema::table('sellers', function (Blueprint $table) {
            $table->index('status', 'sellers_status_index');
            $table->index('deleted_at', 'sellers_deleted_at_index');
            $table->index(['company_id', 'status'], 'sellers_company_status_index');
        });

        // Indexes for credits table (critical for performance)
        Schema::table('credits', function (Blueprint $table) {
            $table->index('status', 'credits_status_index');
            $table->index('deleted_at', 'credits_deleted_at_index');
            // Composite index for the most common query pattern
            $table->index(['seller_id', 'status', 'deleted_at'], 'credits_seller_status_deleted_index');
        });

        // Index for users table (for searches)
        Schema::table('users', function (Blueprint $table) {
            $table->index('name', 'users_name_index');
        });

        // Indexes for payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->index('credit_id', 'payments_credit_id_index');
            $table->index('deleted_at', 'payments_deleted_at_index');
            $table->index(['credit_id', 'deleted_at'], 'payments_credit_deleted_index');
        });

        // Index for user_routes table (for seller-member relationships)
        Schema::table('user_routes', function (Blueprint $table) {
            $table->index(['user_id', 'seller_id'], 'user_routes_user_seller_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops all indexes created in the up() method.
     */
    public function down(): void
    {
        // Drop indexes from sellers table
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropIndex('sellers_status_index');
            $table->dropIndex('sellers_deleted_at_index');
            $table->dropIndex('sellers_company_status_index');
        });

        // Drop indexes from credits table
        Schema::table('credits', function (Blueprint $table) {
            $table->dropIndex('credits_status_index');
            $table->dropIndex('credits_deleted_at_index');
            $table->dropIndex('credits_seller_status_deleted_index');
        });

        // Drop index from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_name_index');
        });

        // Drop indexes from payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_credit_id_index');
            $table->dropIndex('payments_deleted_at_index');
            $table->dropIndex('payments_credit_deleted_index');
        });

        // Drop index from user_routes table
        Schema::table('user_routes', function (Blueprint $table) {
            $table->dropIndex('user_routes_user_seller_index');
        });
    }
};
