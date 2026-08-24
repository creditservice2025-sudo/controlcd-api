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
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('payment_frequency')) {
            $value = strtolower($this->payment_frequency);
            $map = [
                'diaria' => 'Diaria',
                'diario' => 'Diaria',
                'diarios' => 'Diaria',
                'daily' => 'Diaria',
                'semanal' => 'Semanal',
                'semanales' => 'Semanal',
                'weekly' => 'Semanal',
                'quincenal' => 'Quincenal',
                'quincenales' => 'Quincenal',
                'biweekly' => 'Quincenal',
                'mensual' => 'Mensual',
                'mensuales' => 'Mensual',
                'monthly' => 'Mensual',
            ];

            if (isset($map[$value])) {
                $this->merge(['payment_frequency' => $map[$value]]);
            }
        }
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
            'guarantor_id' => 'nullable|exists:guarantors,id',
            'seller_id' => 'required|exists:sellers,id',
            // 'start_date' => 'required|date',
            // 'end_date' => 'required|date',
            'credit_value' => 'nullable|numeric',
            'interest_rate' => 'nullable|numeric',
            'installment_count' => 'nullable|numeric',
            'payment_frequency' => 'nullable|in:Diaria,Semanal,Quincenal,Mensual',
            'excluded_days' => 'nullable|array',
            'micro_insurance_percentage' => 'required|numeric',
            'micro_insurance_amount' => 'nullable|numeric',
            'first_quota_date' => 'nullable|date',
            'is_advance_payment' => 'nullable|boolean',
            'timezone' => 'nullable|string',
            'phone' => 'required|string|min:7',
            'images' => 'required|array|min:1',
            'images.*.file' => 'required|image',
            // La ubicación real de la foto (dinero en mano) es obligatoria al
            // crear: cada captura debe registrar dónde se tomó (evidencia por
            // crédito). No se hereda del cliente.
            'images.0.latitude' => 'required|numeric',
            'images.0.longitude' => 'required|numeric',
            'number_installments' => 'required|integer|min:1'
        ];

        if ($this->isMethod('put') || $this->isMethod('get')) {
            // La edición no re-captura la foto, así que no se re-exige la ubicación.
            $rules['images.0.latitude'] = 'nullable|numeric';
            $rules['images.0.longitude'] = 'nullable|numeric';
            $rules['client_id'] = 'nullable|exists:clients,id';
            $rules['guarantor_id'] = 'nullable|exists:guarantors,id';
            $rules['seller_id'] = 'nullable|exists:sellers,id';
            // $rules['start_date'] = 'nullable|date';
            // $rules['end_date'] = 'nullable|date';
            $rules['credit_value'] = 'nullable|numeric';
            $rules['first_quota_date'] = 'nullable|date';
            $rules['payment_frequency'] = 'nullable|in:Diaria,Semanal,Quincenal,Mensual';
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
            'guarantor_id.exists' => 'El fiador no existe',
            'seller_id.required' => 'El vendedor es requerido',
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
            'phone.required' => 'El teléfono es obligatorio',
            'phone.min' => 'El teléfono debe tener al menos 7 caracteres',
            'micro_insurance_percentage.required' => 'El porcentaje de microseguro es obligatorio',
            'micro_insurance_percentage.numeric' => 'El porcentaje de microseguro debe ser un número',
            'images.required' => 'La foto es obligatoria para crear el crédito',
            'images.array' => 'Formato de imágenes no válido',
            'images.min' => 'Debe subir al menos una foto',
            'images.*.file.required' => 'El archivo de imagen es obligatorio',
            'images.*.file.image' => 'El archivo debe ser una imagen válida',
            'images.0.latitude.required' => 'La ubicación (GPS) de la foto es obligatoria. Activá el GPS y usá "Reintentar".',
            'images.0.longitude.required' => 'La ubicación (GPS) de la foto es obligatoria. Activá el GPS y usá "Reintentar".',
            'images.0.latitude.numeric' => 'La latitud de la foto no es válida',
            'images.0.longitude.numeric' => 'La longitud de la foto no es válida',
        ];
    }
}
