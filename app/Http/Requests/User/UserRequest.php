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
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'dni' => 'required|integer|unique:users',
            'address' => 'required|string|max:255',
            'password' => 'required|string|min:8',
            'routes' => 'array',
            'phone' => 'required|integer|unique:users',
            'role_id' => 'required|integer',
            'timezone' => 'nullable|string',
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
            'phone.unique' => 'El teléfono ya se encuentra en uso',
            'address.required' => 'La dirección es requerida',
            'email.unique' => 'El email ya existe',
            'dni.unique' => 'El dni ya existe',
            'password.min' => 'La contraseña debe tener minimo 8 caracteres',
            'role_id.required' => 'El rol del miembro es requerido',
        ];
    }
}
