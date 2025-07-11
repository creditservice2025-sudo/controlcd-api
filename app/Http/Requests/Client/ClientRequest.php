<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ClientRequest extends FormRequest
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
            'name' => 'required|string',
            'address' => 'required|string',
            'dni' => 'required|numeric|unique:clients',
            'geolocation' => 'required|array',
            'geolocation.latitude' => 'required|numeric',
            'geolocation.longitude' => 'required|numeric',
            'phone' => 'required|numeric',
            'email' => 'nullable|email|unique:clients',
            'company_name' => 'nullable|string',
            'guarantor_name' => 'nullable|string',
            'guarantor_dni' => 'nullable|numeric|unique:guarantors,dni',
            'guarantor_address' => 'nullable|string',
            'guarantor_phone' => 'nullable|numeric',
            'routing_order' => 'required|integer|min:1',
            'seller_id' => 'required|exists:sellers,id',
            'credit_value' => 'nullable|numeric',
            'interest_rate' => 'nullable|numeric',
            'installment_count' => 'nullable|numeric',
            'payment_frequency' => 'nullable|in:Diaria,Semanal,Quincenal,Mensual',
            'excluded_days' => 'nullable|array',
            'micro_insurance_percentage' => 'nullable|numeric',
            'micro_insurance_amount' => 'nullable|numeric',
            'first_installment_date' => 'nullable|date',







            /* 'guarantors_ids' => 'array', */
            'images' => 'nullable|array',
            /*  'images.*.file' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',  */
            'images.*.type' => 'nullable|string|in:profile,gallery'
        ];

        if ($this->isMethod('get')) {
            $rules = [
                'search' => 'nullable|string|max:255',
                'perpage' => 'nullable|integer|min:1|max:100',
            ];
        }

        if ($this->isMethod('put')) {
            $rules = [
                'name' => 'nullable|string',
                'address' => 'nullable|string',
                'dni' => 'nullable|numeric|unique:clients,dni,' . $this->route('clientId'),
                'phone' => 'nullable|numeric',
                'email' => 'nullable|email|unique:clients,email,' . $this->route('clientId'),
                'geolocation' => 'nullable|array',
                'geolocation.latitude' => 'nullable|numeric',
                'geolocation.longitude' => 'nullable|numeric',
            ];
        }

        return $rules;
    }



    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es requerido',
            'address.required' => 'La dirección es requerida',
            'dni.required' => 'El DNI es requerido',
            'dni.numeric' => 'El DNI debe ser un número',
            'dni.unique' => 'El DNI ya existe',
            'routing_order.required' => 'El orden de rutas es requerido',
            'routing_order.integer' => 'El orden de rutas debe ser un número entero',
            'routing_order.min' => 'El orden de rutas debe ser mayor a 0',  
            'geolocation.required' => 'La geolocalización es requerida',
            'geolocation.latitude.required' => 'La latitud es requerida',
            'geolocation.latitude.numeric' => 'La latitud debe ser un número',
            'geolocation.longitude.required' => 'La longitud es requerida',
            'geolocation.longitude.numeric' => 'La longitud debe ser un número',
            'phone.required' => 'El teléfono es requerido',
            'phone.numeric' => 'El teléfono debe ser un número',
            'email.email' => 'El correo electrónico debe ser una dirección de correo válida',
            'email.unique' => 'El correo electrónico ya existe',
            'guarantors_ids.array' => 'Los fiadores deben ser un array',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser una imagen válida',
            'image.max' => 'La imagen debe ser menor a 2MB'
        ];
    }
}
