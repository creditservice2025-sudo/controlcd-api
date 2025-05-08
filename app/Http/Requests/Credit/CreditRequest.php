<?php

namespace App\Http\Requests\Credit;

use Illuminate\Foundation\Http\FormRequest;

class CreditRequest extends FormRequest
{
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
            'client_id' => 'required|exists:clients,id',
            'guarantor_id' => 'required|exists:guarantors,id',
            'route_id' => 'nullable|exists:routes,id',
            // 'start_date' => 'required|date',
            // 'end_date' => 'required|date',
            'credit_value' => 'required|numeric',
            'number_installments' => 'required|integer',
            'first_quota_date' => 'required|date',
            'payment_frequency' => 'required|in:daily,weekly,biweekly,monthly',
            // 'status' => 'required|in:Pendiente,Cancelado,Finalizado,Renovado,Moroso',
            // 'total_interest' => 'required|numeric',

        ];

        if ($this->isMethod('put' ) || $this->isMethod('get')) {
            $rules['client_id'] = 'nullable|exists:clients,id';
            $rules['guarantor_id'] = 'nullable|exists:guarantors,id';
            $rules['route_id'] = 'nullable|exists:routes,id';
            // $rules['start_date'] = 'nullable|date';
            // $rules['end_date'] = 'nullable|date';
            $rules['credit_value'] = 'nullable|numeric';
            $rules['first_quota_date'] = 'nullable|date';
            $rules['payment_frequency'] = 'nullable|in:daily,weekly,biweekly,monthly';
            //$rules['status'] = 'nullable|in:Pendiente,Cancelado,Finalizado,Renovado,Moroso';
            //$rules['total_interest'] = 'nullable|numeric';
            $rules['number_installments'] = 'nullable|integer';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'El cliente es requerido',
            'client_id.exists' => 'El cliente no existe',
            'guarantor_id.required' => 'El fiador es requerido',
            'guarantor_id.exists' => 'El fiador no existe',
            'route_id.required' => 'La ruta es requerida',
            'start_date.required' => 'La fecha de inicio es requerida',
            'start_date.date' => 'La fecha de inicio debe ser una fecha válida',
            'end_date.required' => 'La fecha final es requerida',
            'end_date.date' => 'La fecha final debe ser una fecha válida',
            'credit_value.required' => 'El valor del crédito es requerido',
            'credit_value.numeric' => 'El valor del crédito debe ser un número',
            'first_quota_date.required' => 'La fecha de la primera cuota es requerida',
            'first_quota_date.date' => 'La fecha de la primera cuota debe ser una fecha válida',
            'payment_frequency.required' => 'La frecuencia de pago es requerida',
            'payment_frequency.in' => 'La frecuencia de pago debe ser una de las opciones válidas',
            'status.required' => 'El estado es requerido',
            'status.in' => 'El estado debe ser una de las opciones válidas',
            'total_interest.required' => 'El interés total es requerido',
            'total_interest.numeric' => 'El interés total debe ser un número',
            'number_installments.required' => 'El número de cuotas es requerido',
            'number_installments.integer' => 'El número de cuotas debe ser un número entero',
        ];
    }
}