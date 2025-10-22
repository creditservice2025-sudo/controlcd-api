<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveShowCajaBalanceOfflineFromSellerConfigs extends Migration
{
    public function up()
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->dropColumn('show_caja_balance_offline');
        });
    }

    public function down()
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->boolean('show_caja_balance_offline')->default(false);
        });
    }
}
