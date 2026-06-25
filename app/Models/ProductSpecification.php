<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecification extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','group','name','value'];


    /**
     * @param array $input
     * @param Product $product
     * @return void
     */
    final public function storeProductSpecification(array $input, Product $product):void
    {
        $specification_data = $this->PrepareSpecificationData($input, $product);
        foreach($specification_data as $specification){
            self::create($specification);
        }
    }

    /**
     * @param array $input
     * @param Product $product
     * @return array
     */
    final public function PrepareSpecificationData(array $input, Product $product):array
    {
        $specification_data = [];
        foreach ($input as  $key =>$value){
            $data['product_id'] = $product->id;
            $data['name'] =$value ['name'];
            $data['value'] =$value ['value'];
            $specification_data[]=$data;
        }
        return $specification_data;
    }

    final public function updateProductSpecification(array $input, Product $product)
    {
        $specification_data = $this->PrepareSpecificationData($input, $product);

        // The edit form always submits the full desired specification set (it has no concept
        // of a stable row id, just name/value pairs), so this is a replace, not a merge —
        // matching by name alone would also silently orphan a row if the user renames it.
        // Simplest correct approach: clear and re-insert.
        $this->where('product_id', $product->id)->delete();

        foreach ($specification_data as $specification) {
            self::create($specification);
        }
    }

    final public function specifications(): BelongsTo
    {
        return $this->belongsTo(ProductSpecification::class, 'productSpecification_id');
    }
}
