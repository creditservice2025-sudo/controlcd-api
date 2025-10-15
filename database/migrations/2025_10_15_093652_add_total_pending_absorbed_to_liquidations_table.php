<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->decimal('total_pending_absorbed', 15, 2)->default(0)->after('renewal_disbursed_total');
        });
    }

    public function down()
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn('total_pending_absorbed');
        });
    }
};