<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'nullable|string|max:100',
            'email'              => 'required|email|unique:users,email',
            'phone'              => 'nullable|string|max:20|unique:users,phone',
            'phone_country_code' => 'nullable|string|max:5',
            'date_of_birth'      => 'nullable|date|before:today',
            'gender'             => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'password'           => 'required|string|min:8',
            'role'               => 'required|string|exists:roles,name',
            'employee_type'      => 'nullable|integer|in:1,2,3,4',
            'staff_shop_id'      => 'nullable|integer|exists:shops,id',
            'bio'                => 'nullable|string|max:1000',
            'nid'                => ['nullable', 'digits_between:10,17', 'regex:/^\d+$/'],
            'avatar'             => 'nullable|string|max:14000000',
            'nid_photo'          => 'nullable|string|max:14000000',
        ];
    }
}
