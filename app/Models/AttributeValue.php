<?php

namespace App\Models;

use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeValue extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'attribute_id', 'status', 'user_id'];

    /**
     * @return BelongsTo
     */
    final public function user():BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * @return BelongsTo
     */
    final public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * @return HasMany
     */
    final public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'attribute_value_id');
    }
}
