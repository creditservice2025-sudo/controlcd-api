<?php
namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class CompanyRucRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'ruc' => 'required|string|size:11|unique:companies,ruc',
        ];
    }
}
