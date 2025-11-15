<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('seller_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->integer('notify_renewal_quota')->default(1);
            $table->boolean('notify_discount_cancel')->default(false);
            $table->integer('notify_expense_limit')->default(0);
            $table->boolean('notify_shortage_surplus')->default(true);
            $table->integer('notify_new_credit_amount_limit')->default(0);
            $table->integer('notify_new_credit_count_limit')->default(0);
            $table->integer('restrict_new_sales_amount')->default(0);
            $table->boolean('caja_general_negative')->default(true);
            $table->boolean('show_caja_balance_offline')->default(true);
            $table->boolean('auto_base_next_day')->default(false);
            $table->boolean('require_address_phone')->default(true);
            $table->boolean('auto_closures_collectors')->default(false);
            $table->boolean('require_approval_new_sales')->default(false);
            $table->integer('notify_renewal_quota_alt')->default(1);
            $table->boolean('notify_discount_cancel_alt')->default(false);
            $table->integer('notify_expense_limit_alt')->default(0);
            $table->boolean('notify_shortage_surplus_alt')->default(true);
            $table->integer('notify_new_credit_amount_limit_alt')->default(0);
            $table->integer('notify_new_credit_count_limit_alt')->default(0);
            $table->integer('restrict_new_sales_amount_alt')->default(0);
            $table->boolean('caja_general_negative_alt')->default(true);
            $table->boolean('show_caja_balance_offline_alt')->default(true);
            $table->boolean('auto_base_next_day_alt')->default(false);
            $table->boolean('require_address_phone_alt')->default(true);
            $table->boolean('auto_closures_collectors_alt')->default(false);
            $table->boolean('require_approval_new_sales_alt')->default(false);
            $table->timestamps();
            $table->foreign('seller_id')->references('id')->on('sellers')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('seller_configs');
    }
};
