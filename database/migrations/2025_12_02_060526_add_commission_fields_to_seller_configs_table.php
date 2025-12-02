<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->boolean('commission_system_active')->default(false)->after('seller_id');
            $table->decimal('commission_utility_recon_madrid', 10, 2)->nullable()->after('commission_system_active');
            $table->decimal('commission_total_collection', 10, 2)->nullable()->after('commission_utility_recon_madrid');
            $table->decimal('commission_regrouping', 10, 2)->nullable()->after('commission_total_collection');
            $table->decimal('commission_paid_credits', 10, 2)->nullable()->after('commission_regrouping');
            $table->decimal('monthly_fixed_salary', 10, 2)->nullable()->after('commission_paid_credits');
            $table->decimal('pension_discount', 10, 2)->nullable()->after('monthly_fixed_salary');
            $table->decimal('eps_discount', 10, 2)->nullable()->after('pension_discount');
            $table->decimal('arl_discount', 10, 2)->nullable()->after('eps_discount');
            $table->decimal('weekly_allowance', 10, 2)->nullable()->after('arl_discount');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('seller_configs', function (Blueprint $table) {
            $table->dropColumn([
                'commission_system_active',
                'commission_utility_recon_madrid',
                'commission_total_collection',
                'commission_regrouping',
                'commission_paid_credits',
                'monthly_fixed_salary',
                'pension_discount',
                'eps_discount',
                'arl_discount',
                'weekly_allowance'
            ]);
        });
    }
};
