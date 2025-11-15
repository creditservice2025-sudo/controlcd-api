<?php

namespace App\Http\Requests\Liquidation;

use Illuminate\Foundation\Http\FormRequest;

class CalculateLiquidationRequest extends FormRequest
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
        ];
    }
}