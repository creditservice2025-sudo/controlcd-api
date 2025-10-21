<?php
namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

class CategoryStoreRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'name' => 'required|string|max:255|unique:categories,name',
        ];
    }
    public function messages() {
        return [
            'name.unique' => 'Ya tienes una categoría con este nombre',
        ];
    }
}
