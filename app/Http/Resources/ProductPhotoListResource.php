<?php

namespace App\Http\Resources;

use App\Manager\ImageUploadManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPhotoListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'url'        => ImageUploadManager::url($this->photo_full),
            'thumbnail'  => ImageUploadManager::url($this->photo),
            'alt_text'   => $this->alt_text ?? '',
            'width'      => $this->width ?? null,
            'height'     => $this->height ?? null,
            'position'   => $this->position ?? 0,
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
