<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveCompanyNameFromGuarantors extends Migration
{
    public function up()
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropColumn('company_name');
        });
    }

    public function down()
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->string('company_name')->nullable(); 
        });
    }
}


