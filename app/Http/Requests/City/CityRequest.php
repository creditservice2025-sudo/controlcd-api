<?php

namespace App\Http\Requests\City;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CityRequest extends FormRequest
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
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'country_id' => 'required|exists:countries,id'
        ];
    
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['name'][] = Rule::unique('cities')->ignore($this->route('id'));
        } else {
            $rules['name'][] = 'unique:cities';
        }
    
        return $rules;
    }

    /**
     * Custom validation messages
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la ciudad es obligatorio',
            'name.unique' => 'Esta ciudad ya existe',
            'country_id.required' => 'Debe seleccionar un país',
            'country_id.exists' => 'El país seleccionado no es válido'
        ];
    }
}