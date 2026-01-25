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
            $table->integer('clients_full_payment_count')->default(0)->after('clients_liquidated_count');
            $table->integer('clients_partial_payment_count')->default(0)->after('clients_full_payment_count');
            $table->integer('clients_liquidated_and_renewed_count')->default(0)->after('clients_partial_payment_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn([
                'clients_full_payment_count',
                'clients_partial_payment_count',
                'clients_liquidated_and_renewed_count'
            ]);
        });
    }
};
