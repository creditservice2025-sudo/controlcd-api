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
        Schema::table('credits', function (Blueprint $table) {
            $table->dropForeign(['guarantor_id']);

            $table->unsignedBigInteger('guarantor_id')->nullable()->change();

            $table->foreign('guarantor_id')
                ->references('id')
                ->on('guarantors')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            //
        });
    }
};
