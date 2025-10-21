<?php
namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class ExportDateRangeRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ];
    }
}
