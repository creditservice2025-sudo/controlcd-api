<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:191',
            'country_id'  => 'required|integer|exists:countries,id',
            'recurring'   => 'required|boolean',
            // Recurrente: exige día/mes. No recurrente: exige fecha exacta.
            'month'       => 'required_if:recurring,1,true|nullable|integer|min:1|max:12',
            'day'         => 'required_if:recurring,1,true|nullable|integer|min:1|max:31',
            'date'        => 'required_if:recurring,0,false|nullable|date',
            'active'      => 'nullable|boolean',
            // Solo lo usa el Super-Admin (para administrar a nombre de una empresa).
            'company_id'  => 'nullable|integer|exists:companies,id',
        ];
    }

    public function messages(): array
    {
        return [
            'month.required_if' => 'El mes es obligatorio para un feriado recurrente.',
            'day.required_if'   => 'El día es obligatorio para un feriado recurrente.',
            'date.required_if'  => 'La fecha es obligatoria para un feriado de fecha exacta.',
        ];
    }
}
