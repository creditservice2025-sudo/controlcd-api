<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUnifiedToIdToCreditsTable extends Migration
{
    public function up()
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->unsignedBigInteger('unified_to_id')->nullable()->after('renewed_to_id');
            $table->foreign('unified_to_id')->references('id')->on('credits');
        });
    }

    public function down()
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->dropForeign(['unified_to_id']);
            $table->dropColumn('unified_to_id');
        });
    }
}