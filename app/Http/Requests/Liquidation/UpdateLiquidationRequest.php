<?php

namespace App\Http\Requests\Liquidation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiquidationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'date' => 'nullable|date',
            'seller_id' => 'nullable|exists:sellers,id',
            'cash_delivered' => 'nullable|numeric|min:0',
            'initial_cash' => 'nullable|numeric',
            'base_delivered' => 'nullable|numeric|min:0',
            'total_collected' => 'nullable|numeric|min:0',
            'total_expenses' => 'nullable|numeric|min:0',
            'new_credits' => 'nullable|numeric|min:0',
            'total_income' => 'nullable|numeric|min:0',
            'collection_target' => 'nullable|numeric|min:0',
            'created_at' => 'nullable|date',
        ];
    }
}