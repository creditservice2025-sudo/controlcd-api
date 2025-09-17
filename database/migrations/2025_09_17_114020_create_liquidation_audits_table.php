<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLiquidationAuditsTable extends Migration
{
    public function up()
    {
        Schema::create('liquidation_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('liquidation_id');
            $table->unsignedBigInteger('user_id');
            $table->string('action'); 
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->foreign('liquidation_id')->references('id')->on('liquidations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('liquidation_audits');
    }
}