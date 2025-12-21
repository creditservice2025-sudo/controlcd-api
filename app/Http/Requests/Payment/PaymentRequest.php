<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
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
            'installment_id' => 'nullable|exists:installments,id',
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string',
            'status' => 'required|string|in:Abonado,Pagado,No Pagado,Devuelto,Aplicado',
            'payment_reference' => 'nullable|string',
            'credit_id' => 'required|exists:credits,id',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
            'timezone' => 'nullable|string',
            'client_created_at' => 'nullable|date',
            'client_timezone' => 'nullable|string',
        ];

        // Si el monto es mayor a 0, requerir payment_method
        if ($this->input('amount') > 0) {
            $rules['payment_method'] = 'required|string';
        }

        if ($this->isMethod('put')) {
            $rules['installment_id'] = 'nullable|exists:installments,id';
            $rules['amount'] = 'nullable|numeric|min:0';
            $rules['payment_date'] = 'nullable|date';
            $rules['payment_method'] = 'nullable|string|in:cash,transfer,check';
            $rules['status'] = 'nullable|string|in:Abonado,Pagado,No Pagado,Devuelto, Aplicado';
            $rules['payment_reference'] = 'nullable|numeric';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'credit_id.required' => 'El crédito es requerido',
            'credit_id.exists' => 'El crédito no existe',
            'installment_id.exists' => 'La cuota no existe',
            'amount.required' => 'El monto es requerido',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor que 0',
            'payment_date.required' => 'La fecha de pago es requerida',
            'payment_date.date' => 'La fecha de pago debe ser una fecha válida',
            'payment_method.required' => 'El método de pago es requerido',
            'payment_method.in' => 'El método de pago debe ser cash, transfer o check',
            'status.required' => 'El estado es requerido',
            'status.in' => 'El estado debe ser Abonado, Pagado, No Pagado o Devuelto',
            'payment_reference.numeric' => 'El número de referencia debe ser numerico',
        ];
    }
}
