<?php
namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'clients' => 'required|array',
            'clients.*.id' => 'required|exists:clients,id',
            'clients.*.routing_order' => 'required|integer|min:1',
        ];
    }
}
