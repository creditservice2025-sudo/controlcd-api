<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToGuarantorsTable extends Migration
{
    public function up()
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->string('company_name')->after('email');
            $table->string('company_address')->after('company_name');
            $table->string('company_phone')->after('company_address');
        });
    }

    public function down()
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_address', 'company_phone']);
        });
    }
}
