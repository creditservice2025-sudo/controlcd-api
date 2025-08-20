<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
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
        $companyId = $this->route('company') ? $this->route('company')->id : null;

        return [
            // Datos del responsable
            'dni' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($companyId ? Company::find($companyId)->user_id : null)
            ],
            'password' => $companyId ? 'nullable|string|min:6' : 'required|string|min:6',
            
            // Datos de la empresa
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
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
        ];
    }

    public function messages()
    {
        return [
            'code.size' => 'El código debe tener exactamente 3 caracteres alfanuméricos',
            'ruc.size' => 'El RUC debe tener exactamente 11 dígitos',
            'email.unique' => 'El correo ya se encuentra registrado',
            'code.unique' => 'El código ya se encuentra registrado',
            'ruc.unique' => 'El RUC ya se encuentra registrado' ,
        ];
    }
}