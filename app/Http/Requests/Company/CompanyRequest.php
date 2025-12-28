<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('companyId');
        $userId = null;

        if ($companyId) {
            $company = Company::find($companyId);
            $userId = $company ? $company->user_id : null;
        }

        return [
            'dni' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'dni')->ignore($userId)
            ],
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId)
            ],
            'password' => $companyId 
                ? 'nullable|string|min:8|regex:/[A-Z]/|regex:/[0-9]/|regex:/[@$!%*?&]/' 
                : 'required|string|min:8|regex:/[A-Z]/|regex:/[0-9]/|regex:/[@$!%*?&]/|confirmed',
            
            'code' => [
                'required',
                'string',
                'size:3',
                'alpha_num',
                Rule::unique('companies', 'code')->ignore($companyId)
            ],
            'ruc' => [
                'nullable',
                'string',
                'size:11',
                Rule::unique('companies', 'ruc')->ignore($companyId)
            ],
            'company_name' => 'required|string|max:255',
            'company_phone' => 'nullable|string|max:20',
            'company_email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'timezone' => 'nullable|string',
            
            // Campos de plan
            'plan_id' => 'nullable|exists:plans,id',
            'plan_type' => 'nullable|in:free,full',
            'plan_start_date' => 'nullable|date',
            'plan_end_date' => 'nullable|date|after_or_equal:plan_start_date',
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->route('companyId') && !$this->password) {
            $this->request->remove('password');
        }
    }

    public function messages()
    {
        return [
            'code.size' => 'El código debe tener exactamente 3 caracteres alfanuméricos',
            'ruc.size' => 'El RUC debe tener exactamente 11 dígitos',
            'email.unique' => 'El correo ya se encuentra registrado',
            'code.unique' => 'El código ya se encuentra registrado',
            'ruc.unique' => 'El RUC ya se encuentra registrado',
            'dni.unique' => 'El Documento ya se encuentra registrado',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'password.regex' => 'La contraseña debe incluir mayúsculas, números y caracteres especiales (@$!%*?&)',
            'password.confirmed' => 'Las contraseñas no coinciden',
            'logo.mimes' => 'El archivo debe ser una imagen',
            'logo.max' => 'El archivo debe ser menor a 2MB',
            'logo.image' => 'El archivo debe ser una imagen',
            'name.required' => 'El nombre es requerido',
            'email.required' => 'El correo es requerido',
            'email.email' => 'Ingrese un formato de correo válido',
            'password.required' => 'La contraseña es requerida',
            'dni.required' => 'El Documento es requerido',
            'code.required' => 'El código es requerido',
            'plan_type.in' => 'El tipo de plan debe ser free o full',
            'plan_end_date.after_or_equal' => 'La fecha de fin debe ser posterior a la fecha de inicio',
        ];
    }
}