<?php
namespace App\Http\Requests\Liquidation;

use Illuminate\Foundation\Http\FormRequest;

class ReopenRouteRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'seller_id' => 'required|exists:sellers,id',
            'date' => 'required|date',
        ];
    }
}
