<?php
namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ToggleStatusRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'status' => 'required|string|in:active,inactive,uncollectible',
        ];
    }
}
