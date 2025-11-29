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
                Rule::unique('cities')->where(function ($query) {
                    return $query->where('country_id', $this->country_id);
                })
            ],
            'country_id' => 'required|exists:countries,id',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE'
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['name'][3] = $rules['name'][3]->ignore($this->route('id'));
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
            'name.unique' => 'Ya existe una ciudad con este nombre en el país seleccionado',
            'country_id.required' => 'Debe seleccionar un país',
            'country_id.exists' => 'El país seleccionado no es válido'
        ];
    }
}
