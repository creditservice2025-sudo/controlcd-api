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
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'reference' => 'required|string|max:255',
            'gps_address' => 'nullable|string',
            'gps_geolocalization' => 'nullable|array',
            'dni' => 'required|numeric', // Unique per seller validated in ClientService
            'geolocation' => 'required|array',
            'geolocation.latitude' => 'required|numeric',
            'geolocation.longitude' => 'required|numeric',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|unique:clients',
            'company_name' => 'required|string',
            'guarantor_name' => 'nullable|string',
            'guarantor_dni' => 'nullable|numeric|unique:guarantors,dni',
            'guarantor_address' => 'nullable|string',
            'guarantor_phone' => 'nullable|string|max:20',
            'routing_order' => 'required|integer|min:1',
            'seller_id' => 'required|exists:sellers,id',
            'credit_value' => 'required|numeric|min:1',
            'interest_rate' => 'required|numeric|min:0',
            'installment_count' => 'required|numeric|min:1',
            'payment_frequency' => 'required|in:Diaria,Semanal,Quincenal,Mensual',
            'excluded_days' => 'nullable|array',
            'micro_insurance_percentage' => 'required|numeric|min:0|max:100',
            'micro_insurance_amount' => 'nullable|numeric',
            'is_advance_payment' => 'nullable|boolean',
            'first_installment_date' => 'nullable|date',
            'timezone' => 'nullable|string',
            'date' => 'nullable|date',







            /* 'guarantors_ids' => 'array', */
            'images' => 'required|array|min:4',
            'images.*.file' => 'required|image|max:2048',
            'images.*.type' => 'required|string|in:profile,money_in_hand,business,document'
        ];

        if ($this->isMethod('get')) {
            $rules = [
                'search' => 'nullable|string|max:255',
                'perpage' => 'nullable|integer|min:1|max:100',
            ];
        }

        if ($this->isMethod('put')) {
            $clientId = $this->route('id');
            $client = null;
            if (!is_numeric($clientId)) {
                $client = \App\Models\Client::where('uuid', $clientId)->first();
                $clientId = $client ? $client->id : null;
            } else {
                $client = \App\Models\Client::find($clientId);
            }

            $sellerId = $this->seller_id ?? ($client ? $client->seller_id : null);

            $rules = [
                'name' => 'nullable|string|max:255',
                'address' => 'nullable|string',
                'reference' => 'nullable|string|max:255',
                'gps_address' => 'nullable|string',
                'gps_geolocalization' => 'nullable|array',
                'dni' => 'nullable|numeric|unique:clients,dni,' . $clientId . ',id,seller_id,' . $sellerId . ',deleted_at,NULL',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|unique:clients,email,' . $clientId,
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
            'name.required' => 'El nombre es obligatorio',
            'company_name.required' => 'El nombre del negocio es obligatorio',
            'address.required' => 'La dirección es obligatoria',
            'reference.required' => 'El punto de referencia es obligatorio',
            'dni.required' => 'El DNI es obligatorio',
            'dni.numeric' => 'El DNI debe ser solo números',
            'dni.unique' => 'Este DNI ya está registrado en esta ruta',
            'routing_order.required' => 'El orden de ruta es obligatorio',
            'routing_order.integer' => 'El orden de ruta debe ser un número',
            'routing_order.min' => 'El orden debe ser mayor a 0',
            'geolocation.required' => 'La geolocalización es obligatoria',
            'geolocation.latitude.required' => 'La latitud es obligatoria',
            'geolocation.latitude.numeric' => 'La latitud debe ser un número',
            'geolocation.longitude.required' => 'La longitud es obligatoria',
            'geolocation.longitude.numeric' => 'La longitud debe ser un número',
            'phone.required' => 'El teléfono es obligatorio',
            'email.email' => 'El correo electrónico no es válido',
            'email.unique' => 'Este correo ya está registrado',
            'guarantors_ids.array' => 'Los fiadores deben ser un listado',
            'images.required' => 'Las 4 fotos (Perfil, Fachada, Dinero en mano y Documento) son obligatorias',
            'images.min' => 'Debe subir exactamente las 4 fotos obligatorias',
            'images.*.file.required' => 'El archivo de imagen es obligatorio',
            'images.*.file.image' => 'El archivo debe ser una imagen (JPG, PNG)',
            'images.*.file.max' => 'La imagen no puede pesar más de 2MB',
            'images.*.type.required' => 'El tipo de imagen es obligatorio',
            'images.*.type.in' => 'El tipo de imagen no es válido',
            'credit_value.required' => 'El valor del crédito es obligatorio',
            'installment_count.required' => 'El número de cuotas es obligatorio',
            'interest_rate.required' => 'La tasa de interés es obligatoria',
            'payment_frequency.required' => 'La frecuencia de pago es obligatoria',
            'micro_insurance_percentage.required' => 'El porcentaje de seguro es obligatorio',
        ];
    }
}
