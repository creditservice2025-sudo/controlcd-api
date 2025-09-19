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
            $table->decimal('total_renewal_disbursed', 15, 2)->default(0)->after('total_income');
            $table->decimal('total_crossed_credits', 15, 2)->default(0)->after('total_renewal_disbursed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn(['total_renewal_disbursed', 'total_crossed_credits']);
        });
    }
};
