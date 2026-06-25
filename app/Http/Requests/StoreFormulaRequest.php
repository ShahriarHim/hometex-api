<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'formula'     => 'required|string|max:255',
            'field_limit' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status'      => 'required|numeric',
        ];
    }
}
