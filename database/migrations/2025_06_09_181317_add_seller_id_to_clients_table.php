<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSellerIdToClientsTable extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('company_name')->nullable();
            $table->string('company_address')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->foreign('seller_id')->references('id')->on('sellers');
            $table->unsignedBigInteger('guarantor_id')->nullable();
            $table->foreign('guarantor_id')->references('id')->on('guarantors');
        });
    }


    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {});
    }
}
