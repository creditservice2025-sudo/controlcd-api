<?php
namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateClientsByCriteriaRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'country_id' => 'nullable|integer|exists:countries,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'seller_id' => 'nullable|integer|exists:sellers,id',
        ];
    }
}
