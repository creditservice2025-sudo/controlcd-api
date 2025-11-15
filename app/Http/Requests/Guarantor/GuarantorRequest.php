<?php

namespace App\Http\Requests\Guarantor;

use Illuminate\Foundation\Http\FormRequest;

class GuarantorRequest extends FormRequest
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
        $rules = [
            "name" => "required|string",
            "address" => "required|string",
            "dni" => "required|numeric|unique:guarantors,dni,",
            "phone" => "required|numeric",
            "email" => "email|unique:guarantors,email,",
            'timezone' => 'nullable|string',
        ];



        if ($this->isMethod('put')) {
            $rules = [
                "name" => "nullable|string",
                "address" => "nullable|string",
                "dni" => "nullable|numeric|unique:guarantors,dni,",
                "phone" => "nullable|numeric",
                "email" => "nullable|email|unique:guarantors,email,",
            ];
        }


        return $rules;
    }

    public function messages(): array
    {
        return [
            "name.required" => "El nombre es requerido",
            "address.required" => "La dirección es requerida",
            "dni.required" => "El DNI es requerido",
            "dni.unique" => "El DNI ya está registrado",
            "phone.required" => "El teléfono es requerido",
            "email.email" => "El email debe ser una dirección de correo electrónico válida",
            "email.unique" => "El email ya está registrado",
        ];
    }
}
