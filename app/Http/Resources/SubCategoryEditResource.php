<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Manager\ImageUploadManager;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SubCategoryEditResource
 * 
 * Resource for editing level 2 categories (subcategories) in the unified Category model
 * 
 * @property mixed $id
 * @property mixed $name
 * @property mixed $slug
 * @property mixed $description
 * @property mixed $serial
 * @property mixed $sort_order
 * @property mixed $status
 * @property mixed $is_active
 * @property mixed $photo
 * @property mixed $parent_id
 */

class SubCategoryEditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    final public function toArray(Request $request): array
    {
        // Support both legacy 'status' and new 'is_active' fields
        $status = $this->status ?? ($this->is_active ? 1 : 0);
        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'serial' => $this->serial ?? $this->sort_order,
            'category_id' => $this->parent_id, // Alias for backward compatibility
            'status' => $status,
            'photo_preview' => ImageUploadManager::url($this->photo),
        ];
    }
}
