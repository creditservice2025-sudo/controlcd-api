<?php
namespace App\Http\Requests\Liquidation;

use Illuminate\Foundation\Http\FormRequest;

class LiquidationHistoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'seller_id' => 'required|exists:sellers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ];
    }
}
