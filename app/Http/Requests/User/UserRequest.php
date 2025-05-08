<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|max:8',
            'dni' => 'required|numeric|unique:users',
            'phone' => 'required|numeric',
            'address' => 'required|string',
            'city_id' => 'numeric',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido',
            'email.required' => 'El email es requerido',
            'password.required' => 'La contraseña es requerida',
            'dni.required' => 'El dni es requerido',
            'phone.required' => 'El teléfono es requerido',
            'address.required' => 'La dirección es requerida',
            'email.unique' => 'El email ya existe',
            'dni.unique' => 'El dni ya existe',
        ];
    }
}
