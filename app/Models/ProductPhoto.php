<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPhoto extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'photo', 'photo_full', 'is_primary', 'alt_text', 'width', 'height', 'position'];

    public const PHOTO_WIDTH       = 800;
    public const PHOTO_HEIGHT      = 800;
    public const PHOTO_THUMB_WIDTH  = 200;
    public const PHOTO_THUMB_HEIGHT = 200;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
