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
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['installment_id']);
            $table->dropColumn('installment_id');
            
            $table->unsignedBigInteger('credit_id')->after('id');
            $table->foreign('credit_id')->references('id')->on('credits');
        });
    }
    
    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['credit_id']);
            $table->dropColumn('credit_id');
            
            $table->unsignedBigInteger('installment_id');
            $table->foreign('installment_id')->references('id')->on('installments');
        });
    }
};
