<?php

namespace App\Http\Resources;

use App\Manager\ImageUploadManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


/**
 * CategoryListResource
 * 
 * Resource for level 1 categories (root categories) in the unified Category model
 * 
 * @property mixed $id
 * @property mixed $name
 * @property mixed $slug
 * @property mixed $serial
 * @property mixed $sort_order
 * @property mixed $status
 * @property mixed $is_active
 * @property mixed $user
 * @property mixed $created_at
 * @property mixed $updated_at
 * @property mixed $photo
 * @property mixed $description
 */

class CategoryListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    final public function toArray(Request $request): array
    {
        // Support both legacy 'status' and new 'is_active' fields
        $isActive = $this->is_active ?? ($this->status == 1);
        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => null,
            'parent_name' => null,
            'slug' => $this->slug,
            'description' => $this->description,
            'serial' => $this->serial ?? $this->sort_order,
            'status' => $isActive ? 'Active' : 'Inactive',
            'photo'      => ImageUploadManager::url($this->photo),
            'photo_full' => ImageUploadManager::url($this->photo_full),
            'created_by' => $this->user?->name,
            'created_at' => $this->created_at?->toDayDateTimeString(),
            'updated_at' => $this->created_at != $this->updated_at ? $this->updated_at?->toDayDateTimeString() : 'Not updated yet',
        ];
    }
}
