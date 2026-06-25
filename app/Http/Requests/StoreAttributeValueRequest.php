<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attribute_id' => 'required|numeric|exists:attributes,id',
            'name'         => ['required', 'string', 'min:2', 'max:255',
                Rule::unique('attribute_values')->where('attribute_id', $this->attribute_id),
            ],
            'status'       => 'required|numeric',
        ];
    }
}
