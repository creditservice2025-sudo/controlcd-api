<?php
namespace App\Http\Requests\Liquidation;

use Illuminate\Foundation\Http\FormRequest;

class StoreLiquidationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'date' => 'required|date',
            'seller_id' => 'required|exists:sellers,id',
            'cash_delivered' => 'required|numeric|min:0',
            'path' => 'nullable|image|max:2048',
            'initial_cash' => 'required|numeric',
            'base_delivered' => 'required|numeric|min:0',
            'total_collected' => 'required|numeric|min:0',
            'total_expenses' => 'required|numeric|min:0',
            'total_income' => 'required|numeric|min:0',
            'new_credits' => 'required|numeric|min:0',
            'created_at' => 'nullable|date',
        ];
    }
}