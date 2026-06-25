<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ProductAttribute extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'attribute_id', 'attribute_value_id', 'attribute_math_sign', 'attribute_number', 'shop_quantities', 'attribute_weight', 'attribute_measurement', 'attribute_cost'];

    protected $casts = [
        'shop_quantities' => 'array',
    ];

    /**
     * @param $input
     * @param Product $product
     * @return void
     */
    final public function storeAttribute($input, Product $product): void
    {
        $attribute_data = $this->prepareAttributeData($input, $product);
        foreach ($attribute_data as $attribute) {
            self::create($attribute);
        }
    }

    final public function updateAttribute($input, Product $product)
    {
        $attribute_data = $this->prepareAttributeData($input, $product);

        // The edit form always submits the full desired attribute set, so this is a
        // replace, not a merge — rows for attribute_ids missing from the payload were
        // removed by the user and must be deleted, not left behind.
        $submittedAttributeIds = array_column($attribute_data, 'attribute_id');
        $query = $this->where('product_id', $product->id);
        if (!empty($submittedAttributeIds)) {
            $query->whereNotIn('attribute_id', $submittedAttributeIds);
        }
        $query->delete();

        foreach ($attribute_data as $attribute) {
            $existingAttribute = $this->where('product_id', $product->id)
                ->where('attribute_id', $attribute['attribute_id'])
                ->first();

            if ($existingAttribute) {
                $existingAttribute->update([
                    'attribute_value_id' => $attribute['attribute_value_id'],
                    'attribute_math_sign' => $attribute['attribute_math_sign'],
                    'attribute_number' => $attribute['attribute_number'],
                    'shop_quantities' => $attribute['shop_quantities'],
                    'attribute_weight' => $attribute['attribute_weight'],
                    'attribute_measurement' => $attribute['attribute_measurement'],
                    'attribute_cost' => $attribute['attribute_cost'],
                ]);
            } else {
                self::create($attribute);
            }
        }
    }
    /**
     * @param array $input
     * @param Product $product
     * @return array
     */
    private function prepareAttributeData(array $input, Product $product): array
    {
        $attribute_data = [];
        foreach ($input as  $key => $value) {
            $data = [];
            $data['product_id'] = $product->id;
            $data['attribute_id'] = $value['attribute_id'];
            // Frontend sends "value_id"; some callers may send "attribute_value_id" — accept either.
            // attribute_value_id, attribute_math_sign, attribute_number are NOT NULL columns.
            $data['attribute_value_id'] = $value['value_id'] ?? $value['attribute_value_id'] ?? 0;
            $data['attribute_math_sign'] = $value['math_sign'] ?? '+';
            $number = $value['number'] ?? '';
            $data['attribute_number'] = $number === '' ? 0 : $number;
            $data['shop_quantities'] = json_encode($value['shop_quantities'] ?? []);
            $data['attribute_weight'] = $value['attribute_weight'] ?? null;
            $data['attribute_measurement'] = $value['attribute_mesarment'] ?? $value['attribute_measurement'] ?? null;
            $cost = $value['attribute_cost'] ?? '';
            $data['attribute_cost'] = $cost === '' ? null : $cost;
            $attribute_data[] = $data;
        }

        return $attribute_data;
    }

    /**
     * @return BelongsTo
     */
    final public function attributes(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    /**
     * @return BelongsTo
     */
    final public function attribute_value(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }
}
