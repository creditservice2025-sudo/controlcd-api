<?php

namespace App\Http\Requests\Seller;

use App\Models\Seller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SellerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sellerId = $this->route('sellerId');
        $seller = $sellerId ? Seller::find($sellerId) : null;
        $userId = $seller ? $seller->user_id : null;

        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($userId)
            ],
            'dni' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'dni')->ignore($userId)
            ],
            'city_id' => 'required|exists:cities,id',
            'members' => 'nullable|array',
            'members.*' => 'exists:users,id',
        ];


        $user = Auth::user();
        if ($user && $user->role_id == 2) {
            $rules['company_id'] = 'sometimes|nullable|exists:companies,id';
        } else {
            $rules['company_id'] = 'required|exists:companies,id';
        }

        if ($this->isMethod('post')) {
            $rules['password'] = 'required|string|min:8';
        } else {
            $rules['password'] = 'sometimes|nullable|string|min:8';
        }

        return $rules;
    }

    protected function prepareForValidation()
    {
        $user = Auth::user();
        if ($user && $user->role_id == 2) {
            $this->merge([
                'company_id' => $user->company->id,
            ]);
        }

        if ($this->has('members')) {
            $members = $this->input('members');
            $members = is_array($members)
                ? $members
                : json_decode($members, true) ?? [];
            $this->merge([
                'members' => array_filter(array_map('intval', $members))
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre completo es requerido',
            'email.required' => 'El correo electrónico es requerido',
            'email.email' => 'Ingrese un formato de correo válido',
            'email.unique' => 'Este correo ya está registrado',
            'dni.required' => 'El documento es requerido',
            'dni.unique' => 'Este documento ya está registrado',
            'city_id.required' => 'La ciudad es requerida',
            'city_id.exists' => 'Ciudad seleccionada no válida',
            'password.required' => 'La contraseña es requerida',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'members.*.exists' => 'Uno o más miembros seleccionados son inválidos',
            'company_id.required' => 'La empresa es requerida',
        ];
    }
}
