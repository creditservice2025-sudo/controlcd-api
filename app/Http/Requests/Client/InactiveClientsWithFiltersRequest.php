<?php
namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class InactiveClientsWithFiltersRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'search' => 'nullable|string',
            'orderBy' => 'nullable|string',
            'orderDirection' => 'nullable|in:asc,desc',
            'countryId' => 'nullable|integer|exists:countries,id',
            'cityId' => 'nullable|integer|exists:cities,id',
            'sellerId' => 'nullable|integer|exists:sellers,id',
        ];
    }
}
