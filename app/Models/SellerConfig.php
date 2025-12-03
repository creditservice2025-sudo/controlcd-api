<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerConfig extends Model
{
    protected $fillable = [
        'seller_id',
        'notify_renewal_quota',
        'notify_discount_cancel',
        'notify_expense_limit',
        'notify_shortage_surplus',
        'notify_new_credit_amount_limit',
        'notify_new_credit_count_limit',
        'restrict_new_sales_amount',
        'caja_general_negative',
        'auto_base_next_day',
        'require_address_phone',
        'auto_closures_collectors',
        'require_approval_new_sales',
        'notify_renewal_quota_alt',
        'notify_discount_cancel_alt',
        'notify_expense_limit_alt',
        'notify_shortage_surplus_alt',
        'notify_new_credit_amount_limit_alt',
        'notify_new_credit_count_limit_alt',
        'restrict_new_sales_amount_alt',
        'caja_general_negative_alt',
        'show_caja_balance_offline_alt',
        'auto_base_next_day_alt',
        'require_address_phone_alt',
        'auto_closures_collectors_alt',
        'require_approval_new_sales_alt',
        'commission_system_active',
        'commission_utility_recon_madrid',
        'commission_total_collection',
        'commission_regrouping',
        'commission_paid_credits',
        'commission_paid_credits_type',
        'monthly_fixed_salary',
        'monthly_savings',
        'pension_discount',
        'eps_discount',
        'arl_discount',
        'weekly_allowance',
    ];

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }
}
