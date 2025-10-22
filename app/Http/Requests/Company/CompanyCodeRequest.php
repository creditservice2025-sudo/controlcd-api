<?php
namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class CompanyCodeRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'code' => 'required|string|size:3|alpha_num|unique:companies,code',
        ];
    }
}
