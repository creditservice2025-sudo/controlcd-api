<?php
namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class SellerConfigRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'notify_renewal_quota' => 'required|integer|min:1',
            'notify_discount_cancel' => 'required|boolean',
            'notify_expense_limit' => 'required|integer|min:0',
            'notify_shortage_surplus' => 'required|boolean',
            'notify_new_credit_amount_limit' => 'required|integer|min:0',
            'notify_new_credit_count_limit' => 'required|integer|min:0',
            'restrict_new_sales_amount' => 'required|integer|min:0',
            'caja_general_negative' => 'required|boolean',
            'show_caja_balance_offline' => 'required|boolean',
            'auto_base_next_day' => 'required|boolean',
            'require_address_phone' => 'required|boolean',
            'auto_closures_collectors' => 'required|boolean',
            'require_approval_new_sales' => 'required|boolean',
            'notify_renewal_quota_alt' => 'required|integer|min:1',
            'notify_discount_cancel_alt' => 'required|boolean',
            'notify_expense_limit_alt' => 'required|integer|min:0',
            'notify_shortage_surplus_alt' => 'required|boolean',
            'notify_new_credit_amount_limit_alt' => 'required|integer|min:0',
            'notify_new_credit_count_limit_alt' => 'required|integer|min:0',
            'restrict_new_sales_amount_alt' => 'required|integer|min:0',
            'caja_general_negative_alt' => 'required|boolean',
            'show_caja_balance_offline_alt' => 'required|boolean',
            'auto_base_next_day_alt' => 'required|boolean',
            'require_address_phone_alt' => 'required|boolean',
            'auto_closures_collectors_alt' => 'required|boolean',
            'require_approval_new_sales_alt' => 'required|boolean',
        ];
    }
}
