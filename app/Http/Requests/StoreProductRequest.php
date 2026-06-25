<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' =>'string|required|min:3|max:255',
            'slug' =>'string|required|min:3|max:255|unique:products',
            'sku' =>'string|required|min:3|max:255|unique:products',
            // exists: rules mirror UpdateProductRequest — without them, an invalid/wrong-table
            // id (e.g. from a stale dropdown) is stored silently at create time and only
            // surfaces as a validation error later, on update.
            'brand_id' =>'nullable|numeric|exists:brands,id',
            'country_id' =>'nullable|numeric|exists:countries,id',
            'sub_category_id' =>'nullable|numeric|exists:sub_categories,id',
            'child_sub_category_id' =>'nullable|numeric|exists:child_sub_categories,id',
            'supplier_id' =>'nullable|numeric|exists:suppliers,id',
            'discount_fixed' =>'nullable|numeric',
            'discount_percent' =>'nullable|numeric',
            'discount_start' =>'nullable|date',
            'discount_end' =>'nullable|date|after_or_equal:discount_start',
            'category_id' =>'required|numeric|exists:categories,id',
            'cost' =>'required|numeric',
            'price' =>'numeric',
            'price_formula' =>'string',
            'field_limit' =>'string',
            'status' =>'required|numeric',
            'stock' =>'required|numeric',
            'isFeatured' =>'numeric',
            'isNew' =>'numeric',
            'isTrending' =>'numeric',
            'description' =>'required|max:1000|min:10',
            'attributes' =>'array',
            'attributes.*.attribute_id' => 'required_with:attributes|numeric|exists:attributes,id',
            'attributes.*.value_id' => 'required_with:attributes|numeric|exists:attribute_values,id',
            'specifications' =>'array',
            'specifications.*.name' => 'required_with:specifications|string|max:255',
            'specifications.*.value' => 'required_with:specifications|string|max:1000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'og_image' => 'nullable|string|max:2048',
            // Frontend sends shop_quantities: [{ shop_id, quantity }] — no separate shop_ids array.
            'shop_quantities' => 'required|array',
            'shop_quantities.*.shop_id' => 'required|numeric|exists:shops,id',
            'shop_quantities.*.quantity' => 'required|numeric|min:0',
            // Optional photos uploaded at create time (same shape as POST /product/{id}/photos)
            'photos'              => 'sometimes|array',
            'photos.*.photo'      => 'required_with:photos|string',
            'photos.*.is_primary' => 'sometimes|integer|in:0,1',
            'photos.*.serial'     => 'sometimes|integer',
        ];
    }
}
