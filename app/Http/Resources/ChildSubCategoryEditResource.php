<?php

namespace App\Http\Resources;

use App\Manager\ImageUploadManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ChildSubCategoryEditResource
 * 
 * Resource for editing level 3 categories (child categories) in the unified Category model
 */
class ChildSubCategoryEditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Support both legacy 'status' and new 'is_active' fields
        $status = $this->status ?? ($this->is_active ? 1 : 0);
        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sub_category_id' => $this->parent_id, // Alias for backward compatibility
            'slug' => $this->slug,
            'description' => $this->description,
            'serial' => $this->serial ?? $this->sort_order,
            'status' => $status,
            'photo_preview' => ImageUploadManager::url($this->photo),
        ];
    }
}
