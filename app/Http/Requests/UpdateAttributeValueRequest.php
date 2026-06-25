<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'min:2', 'max:255',
                Rule::unique('attribute_values')
                    ->where('attribute_id', $this->route('attribute_value')->attribute_id)
                    ->ignore($this->route('attribute_value')),
            ],
            'status' => 'required|numeric',
        ];
    }
}
