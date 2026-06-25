<?php

namespace App\Models;

use App\Manager\ImageUploadManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BannerSlider extends Model
{
    use HasFactory;

    protected $table = 'banner_sliders';

    public const STATUS_ACTIVE   = 1;
    public const STATUS_INACTIVE = 0;

    public const PRESETS = [
        'striped_overlay', // animated stripes + floating overlay images + text
        'full_image',      // full-width background image + text overlay
        'split_text',      // left: text block, right: image
        'minimal',         // clean centered text, no image
    ];

    protected $fillable = [
        'name',
        'preset',
        'heading',
        'subheading',
        'button_label',
        'button_url',
        'bg_color',
        'stripe_color',
        'text_color',
        'button_color',
        'text_position',
        'animate_stripes',
        'overlay_images',
        'slider',          // background image R2 key
        'order_position',
        'sl',              // kept for backward compat
        'status',
    ];

    protected $casts = [
        'animate_stripes' => 'boolean',
        'overlay_images'  => 'array',
        'status'          => 'integer',
        'order_position'  => 'integer',
        'sl'              => 'integer',
    ];

    protected $hidden = ['IMAGE_UPLOAD_PATH'];

    /**
     * Full slide data for IMS (admin) — includes all fields + resolved image URLs.
     */
    public function toAdminArray(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'preset'         => $this->preset,
            'heading'        => $this->heading,
            'subheading'     => $this->subheading,
            'button_label'   => $this->button_label,
            'button_url'     => $this->button_url,
            'bg_color'       => $this->bg_color,
            'stripe_color'   => $this->stripe_color,
            'text_color'     => $this->text_color,
            'button_color'   => $this->button_color,
            'text_position'  => $this->text_position ?? 'center',
            'animate_stripes'=> $this->animate_stripes ?? true,
            'slider_url'     => $this->slider ? ImageUploadManager::url($this->slider) : null,
            'overlay_image_urls' => collect($this->overlay_images ?? [])
                ->map(fn ($key) => ImageUploadManager::url($key))
                ->filter()
                ->values()
                ->all(),
            'order_position' => $this->order_position,
            'status'         => $this->status,
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Public-facing slide data for ECOM — same shape, ready for Swiper.
     */
    public function toPublicArray(): array
    {
        return [
            'id'             => $this->id,
            'preset'         => $this->preset,
            'heading'        => $this->heading,
            'subheading'     => $this->subheading,
            'button_label'   => $this->button_label,
            'button_url'     => $this->button_url,
            'bg_color'       => $this->bg_color,
            'stripe_color'   => $this->stripe_color,
            'text_color'     => $this->text_color,
            'button_color'   => $this->button_color,
            'text_position'  => $this->text_position ?? 'center',
            'animate_stripes'=> $this->animate_stripes ?? true,
            'slider_url'     => $this->slider ? ImageUploadManager::url($this->slider) : null,
            'overlay_image_urls' => collect($this->overlay_images ?? [])
                ->map(fn ($key) => ImageUploadManager::url($key))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public function getActiveSliders()
    {
        return self::query()
            ->where('status', self::STATUS_ACTIVE)
            ->orderBy('order_position')
            ->orderBy('sl')
            ->orderBy('id')
            ->get();
    }
}
