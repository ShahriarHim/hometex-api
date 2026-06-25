<?php

namespace App\Http\Resources;

use App\Manager\ImageUploadManager;
use App\Manager\PriceManager;
use App\Models\AttributeValue;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailsListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $productId = $this->product_id ?? (is_string($this->sku) && $this->sku !== ''
            ? Product::where('sku', $this->sku)->value('id')
            : null);

        $attrLabel = null;
        if ($this->relationLoaded('attribute_value') && $this->attribute_value) {
            $attrLabel = ($this->attribute_value->relationLoaded('attribute') && $this->attribute_value->attribute)
                ? $this->attribute_value->attribute->name . ': ' . $this->attribute_value->name
                : $this->attribute_value->name;
        } elseif ($this->attribute_value_id) {
            $av = AttributeValue::with('attribute')->find($this->attribute_value_id);
            $attrLabel = $av ? ($av->attribute?->name ? $av->attribute->name . ': ' . $av->name : $av->name) : null;
        }

        return [
            'id'=>$this->id,
            'product_id'=>$productId,
            'name'=>$this->name,
            'attribute_value_id'=>$this->attribute_value_id,
            'attribute'=>$attrLabel,
            'photo' => ImageUploadManager::url($this->photo),
            'brand'=>$this->brand?->name,
            'category'=>$this->category?->name,
            'sub_category'=>$this->sub_category?->name,
            'child_sub_category'=>$this->child_sub_category?->name,
            'supplier'=>$this->supplier?->name,
            'cost'=>$this->cost . PriceManager::CURRENCY_SYMBOL,
            'price'=>number_format($this->price) . PriceManager::CURRENCY_SYMBOL,
            'sell_price'=>PriceManager::calculate_sell_price($this->price, $this->discount_percent, $this->discount_fixed, $this->discount_start, $this->discount_end ),
            'quantity'=>$this->quantity,
            'line_total' => (int) (PriceManager::calculate_sell_price($this->price, $this->discount_percent, $this->discount_fixed, $this->discount_start, $this->discount_end)['price'] ?? 0) * (int) $this->quantity,
            'sku'=>$this->sku,
            'discount_fixed'=>$this->discount_fixed,
            'discount_percent'=>$this->discount_percent,
            'discount_start'=>$this->discount_start ? Carbon::create($this->discount_start)->toDayDateTimeString():'',
            'discount_end'=>$this->discount_end ? Carbon::create($this->discount_end)->toDayDateTimeString():'',
        ];
    }
}
