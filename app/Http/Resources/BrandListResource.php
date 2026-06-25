<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Manager\ImageUploadManager;
use Illuminate\Http\Resources\Json\JsonResource;


/**
 *@property mixed $id
 *@property mixed $name
 *@property mixed $slug
 *@property mixed $serial
 *@property mixed $status
 *@property mixed $user
 *@property mixed $created_at
 *@property mixed $updated_at
 *@property mixed $logo
 *@property mixed $description
 */

class BrandListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    final public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'serial' => $this->serial,
            'status' => $this->status == 1 ? 'Active' : 'Inactive',
            'photo'      => ImageUploadManager::url($this->logo),
            'photo_full' => ImageUploadManager::url($this->logo_full),
            'created_by' => $this->user?->name,
            'created_at' => $this->created_at->toDayDateTimeString(),
            'updated_at' => $this->created_at != $this->updated_at ? $this->updated_at->toDayDateTimeString() : 'Not updated yet',
        ];
    }
}
