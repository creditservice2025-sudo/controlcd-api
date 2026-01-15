<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if column exists before adding
        if (!Schema::hasColumn('credits', 'number_installments')) {
            Schema::table('credits', function (Blueprint $table) {
                $table->integer('number_installments')->after('credit_value');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('credits', 'number_installments')) {
            Schema::table('credits', function (Blueprint $table) {
                $table->dropColumn('number_installments');
            });
        }
    }
};
