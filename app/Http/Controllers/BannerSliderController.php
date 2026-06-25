<?php

namespace App\Http\Controllers;

use App\Manager\ImageUploadManager;
use App\Models\BannerSlider;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class BannerSliderController extends Controller
{
    private const CACHE_KEY = 'banner_sliders_active';

    // -------------------------------------------------------------------------
    // Public
    // -------------------------------------------------------------------------

    /**
     * GET /hero-banners  — public, ECOM-facing, active slides only.
     */
    public function index(): JsonResponse
    {
        $slides = \Illuminate\Support\Facades\Cache::remember(
            self::CACHE_KEY,
            3600,
            fn () => BannerSlider::query()
                ->where('status', BannerSlider::STATUS_ACTIVE)
                ->orderBy('order_position')
                ->orderBy('sl')
                ->orderBy('id')
                ->get()
        );

        return response()->json([
            'status' => 'success',
            'data'   => $slides->map(fn (BannerSlider $s) => $s->toPublicArray())->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    /**
     * GET /banners  — admin, all slides including inactive.
     */
    public function adminIndex(): JsonResponse
    {
        $slides = BannerSlider::query()
            ->orderBy('order_position')
            ->orderBy('sl')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $slides->map(fn (BannerSlider $s) => $s->toAdminArray())->values(),
        ]);
    }

    /**
     * POST /banners
     * Body: { name, preset, heading?, subheading?, button_label?, button_url?,
     *         bg_color?, stripe_color?, text_color?, button_color?,
     *         text_position?, animate_stripes?, slider?, overlay_images[]?,
     *         order_position?, status? }
     * slider and each overlay_image are base64 strings.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:255',
            'preset'          => 'nullable|string|in:' . implode(',', BannerSlider::PRESETS),
            'heading'         => 'nullable|string|max:255',
            'subheading'      => 'nullable|string|max:255',
            'button_label'    => 'nullable|string|max:100',
            'button_url'      => 'nullable|string|max:500',
            'bg_color'        => 'nullable|string|max:20',
            'stripe_color'    => 'nullable|string|max:20',
            'text_color'      => 'nullable|string|max:20',
            'button_color'    => 'nullable|string|max:20',
            'text_position'   => 'nullable|in:left,center,right',
            'animate_stripes' => 'nullable|boolean',
            'slider'          => 'nullable|string|max:14000000',
            'overlay_images'  => 'nullable|array|max:5',
            'overlay_images.*'=> 'string|max:14000000',
            'order_position'  => 'nullable|integer|min:0',
            'status'          => 'nullable|integer|in:0,1',
        ]);

        // Phase 1 — upload all images to R2 before touching DB
        $uploadedKeys   = [];
        $sliderKey      = null;
        $overlayKeys    = [];

        try {
            if ($request->filled('slider')) {
                $keys      = ImageUploadManager::upload($request->slider, 'banners');
                $sliderKey = $keys['full'];
                $uploadedKeys[] = $sliderKey;
            }

            foreach ($request->input('overlay_images', []) as $b64) {
                $key = ImageUploadManager::uploadFromBase64($b64, 'banners/overlay/' . uniqid());
                $overlayKeys[]  = $key;
                $uploadedKeys[] = $key;
            }
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to upload image.'], 500);
        }

        // Phase 2 — DB insert
        try {
            $slide = BannerSlider::create([
                'name'            => $request->name,
                'preset'          => $request->input('preset', 'striped_overlay'),
                'heading'         => $request->heading,
                'subheading'      => $request->subheading,
                'button_label'    => $request->button_label,
                'button_url'      => $request->button_url,
                'bg_color'        => $request->bg_color,
                'stripe_color'    => $request->stripe_color,
                'text_color'      => $request->text_color,
                'button_color'    => $request->button_color,
                'text_position'   => $request->input('text_position', 'center'),
                'animate_stripes' => $request->input('animate_stripes', true),
                'slider'          => $sliderKey,
                'overlay_images'  => $overlayKeys ?: null,
                'order_position'  => $request->input('order_position', 0),
                'sl'              => $request->input('order_position', 0),
                'status'          => $request->input('status', BannerSlider::STATUS_ACTIVE),
            ]);
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to save slide.'], 500);
        }

        $this->clearCache();

        return response()->json([
            'status'  => 'success',
            'message' => 'Slide created successfully.',
            'data'    => $slide->toAdminArray(),
        ], 201);
    }

    /**
     * PUT /banners/{banner}
     * All fields optional. Omit slider/overlay_images to keep existing.
     */
    public function update(Request $request, BannerSlider $banner): JsonResponse
    {
        $request->validate([
            'name'            => 'nullable|string|max:255',
            'preset'          => 'nullable|string|in:' . implode(',', BannerSlider::PRESETS),
            'heading'         => 'nullable|string|max:255',
            'subheading'      => 'nullable|string|max:255',
            'button_label'    => 'nullable|string|max:100',
            'button_url'      => 'nullable|string|max:500',
            'bg_color'        => 'nullable|string|max:20',
            'stripe_color'    => 'nullable|string|max:20',
            'text_color'      => 'nullable|string|max:20',
            'button_color'    => 'nullable|string|max:20',
            'text_position'   => 'nullable|in:left,center,right',
            'animate_stripes' => 'nullable|boolean',
            'slider'          => 'nullable|string|max:14000000',
            'overlay_images'  => 'nullable|array|max:5',
            'overlay_images.*'=> 'string|max:14000000',
            'order_position'  => 'nullable|integer|min:0',
            'status'          => 'nullable|integer|in:0,1',
        ]);

        $newSliderKey    = null;
        $oldSliderKey    = null;
        $newOverlayKeys  = null;
        $oldOverlayKeys  = null;
        $uploadedKeys    = [];

        try {
            if ($request->filled('slider')) {
                $oldSliderKey   = $banner->slider;
                $keys           = ImageUploadManager::upload($request->slider, 'banners');
                $newSliderKey   = $keys['full'];
                $uploadedKeys[] = $newSliderKey;
            }

            if ($request->has('overlay_images')) {
                $oldOverlayKeys = $banner->overlay_images ?? [];
                $newOverlayKeys = [];
                foreach ($request->input('overlay_images', []) as $b64) {
                    $key = ImageUploadManager::uploadFromBase64($b64, 'banners/overlay/' . uniqid());
                    $newOverlayKeys[] = $key;
                    $uploadedKeys[]   = $key;
                }
            }
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to upload image.'], 500);
        }

        try {
            $updates = array_filter([
                'name'            => $request->name,
                'preset'          => $request->preset,
                'heading'         => $request->heading,
                'subheading'      => $request->subheading,
                'button_label'    => $request->button_label,
                'button_url'      => $request->button_url,
                'bg_color'        => $request->bg_color,
                'stripe_color'    => $request->stripe_color,
                'text_color'      => $request->text_color,
                'button_color'    => $request->button_color,
                'text_position'   => $request->text_position,
                'animate_stripes' => $request->animate_stripes,
                'slider'          => $newSliderKey,
                'overlay_images'  => $newOverlayKeys,
                'order_position'  => $request->order_position,
                'status'          => $request->status,
            ], fn ($v) => $v !== null);

            $banner->update($updates);
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to update slide.'], 500);
        }

        // DB committed — safe to delete old R2 assets
        if ($oldSliderKey)   ImageUploadManager::deleteKey($oldSliderKey);
        if ($oldOverlayKeys) foreach ($oldOverlayKeys as $k) ImageUploadManager::deleteKey($k);

        $this->clearCache();

        return response()->json([
            'status'  => 'success',
            'message' => 'Slide updated successfully.',
            'data'    => $banner->fresh()->toAdminArray(),
        ]);
    }

    /**
     * DELETE /banners/{banner}
     */
    public function destroy(BannerSlider $banner): JsonResponse
    {
        $sliderKey   = $banner->slider;
        $overlayKeys = $banner->overlay_images ?? [];

        $banner->delete();

        if ($sliderKey)   ImageUploadManager::deleteKey($sliderKey);
        foreach ($overlayKeys as $key) ImageUploadManager::deleteKey($key);

        $this->clearCache();

        return response()->json(['status' => 'success', 'message' => 'Slide deleted successfully.']);
    }

    /**
     * PUT /banners/reorder
     * Body: { slides: [{ id, order_position }] }
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'slides'                => 'required|array|min:1',
            'slides.*.id'           => 'required|integer|exists:banner_sliders,id',
            'slides.*.order_position' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request) {
            foreach ($request->slides as $item) {
                BannerSlider::where('id', $item['id'])->update([
                    'order_position' => $item['order_position'],
                    'sl'             => $item['order_position'],
                ]);
            }
        });

        $this->clearCache();

        return response()->json(['status' => 'success', 'message' => 'Slides reordered.']);
    }

    private function clearCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
        CacheService::clearBannerCaches();
    }
}
