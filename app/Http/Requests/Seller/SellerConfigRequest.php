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
            'notify_renewal_quota' => 'nullable|integer|min:1',
            'notify_discount_cancel' => 'nullable|boolean',
            'notify_expense_limit' => 'nullable|integer|min:0',
            'notify_shortage_surplus' => 'nullable|boolean',
            'notify_new_credit_amount_limit' => 'nullable|integer|min:0',
            'notify_new_credit_count_limit' => 'nullable|integer|min:0',
            'restrict_new_sales_amount' => 'nullable|integer|min:0',
            'caja_general_negative' => 'nullable|boolean',
            'auto_base_next_day' => 'nullable|boolean',
            'require_address_phone' => 'nullable|boolean',
            'auto_closures_collectors' => 'nullable|boolean',
            'require_approval_new_sales' => 'nullable|boolean',
            'notify_renewal_quota_alt' => 'nullable|integer|min:1',
            'notify_discount_cancel_alt' => 'nullable|boolean',
            'notify_expense_limit_alt' => 'nullable|integer|min:0',
            'notify_shortage_surplus_alt' => 'nullable|boolean',
            'notify_new_credit_amount_limit_alt' => 'nullable|integer|min:0',
            'notify_new_credit_count_limit_alt' => 'nullable|integer|min:0',
            'restrict_new_sales_amount_alt' => 'nullable|integer|min:0',
            'caja_general_negative_alt' => 'nullable|boolean',
            'show_caja_balance_offline_alt' => 'nullable|boolean',
            'auto_base_next_day_alt' => 'nullable|boolean',
            'require_address_phone_alt' => 'nullable|boolean',
            'auto_closures_collectors_alt' => 'nullable|boolean',
            'require_approval_new_sales_alt' => 'nullable|boolean',
        ];
    }
}
