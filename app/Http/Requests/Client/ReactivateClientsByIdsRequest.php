<?php
namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateClientsByIdsRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'client_ids' => 'required|array',
            'client_ids.*' => 'integer|exists:clients,id',
        ];
    }
}
