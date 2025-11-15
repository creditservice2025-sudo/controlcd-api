<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->decimal('irrecoverable_credits_amount', 15, 2)->default(0)->after('total_expenses');
            $table->decimal('renewal_disbursed_total', 15, 2)->default(0)->after('irrecoverable_credits_amount');
            $table->dateTime('end_date')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn('irrecoverable_credits_amount');
            $table->dropColumn('renewal_disbursed_total');
        });
    }
};
