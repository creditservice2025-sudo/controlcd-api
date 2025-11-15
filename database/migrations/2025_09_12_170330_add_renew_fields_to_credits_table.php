<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->unsignedBigInteger('renewed_from_id')->nullable()->after('id');
            $table->unsignedBigInteger('renewed_to_id')->nullable()->after('renewed_from_id');
            $table->boolean('is_renewed')->default(false)->after('status');
            $table->foreign('renewed_from_id')->references('id')->on('credits');
            $table->foreign('renewed_to_id')->references('id')->on('credits');
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
