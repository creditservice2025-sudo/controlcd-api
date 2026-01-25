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
            $table->integer('active_clients_with_credit_count')->default(0)->after('new_clients_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn('active_clients_with_credit_count');
        });
    }
};
