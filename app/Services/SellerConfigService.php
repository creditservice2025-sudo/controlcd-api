<?php
namespace App\Services;

use App\Models\SellerConfig;

class SellerConfigService
{
    public function getBySeller($sellerId)
    {
        return SellerConfig::where('seller_id', $sellerId)->first();
    }

    public function createOrUpdate($sellerId, array $data)
    {
        $booleanFields = [
            'notify_discount_cancel',
            'notify_shortage_surplus',
            'caja_general_negative',
            'show_caja_balance_offline',
            'auto_base_next_day',
            'require_address_phone',
            'auto_closures_collectors',
            'require_approval_new_sales',
            'notify_discount_cancel_alt',
            'notify_shortage_surplus_alt',
            'caja_general_negative_alt',
            'show_caja_balance_offline_alt',
            'auto_base_next_day_alt',
            'require_address_phone_alt',
            'auto_closures_collectors_alt',
            'require_approval_new_sales_alt',
        ];
        foreach ($booleanFields as $field) {
            if (isset($data[$field])) {
                if ($data[$field] === 'Sí') {
                    $data[$field] = true;
                } elseif ($data[$field] === 'No') {
                    $data[$field] = false;
                }
            }
        }
        return SellerConfig::updateOrCreate(
            ['seller_id' => $sellerId],
            $data
        );
    }
}
