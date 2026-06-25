<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Formula extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'formula', 'field_limit', 'description', 'status', 'user_id'];

    final public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
