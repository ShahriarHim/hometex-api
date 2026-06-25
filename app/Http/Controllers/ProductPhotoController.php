<?php

namespace App\Http\Controllers;

use App\Manager\ImageUploadManager;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Http\Requests\StoreProductPhotoRequest;
use App\Http\Requests\UpdateProductPhotoRequest;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Throwable;

class ProductPhotoController extends Controller
{
    /**
     * Upload one or more photos for a product.
     *
     * Phase 1: upload all R2 keys.
     * Phase 2: insert all rows in a single transaction.
     * On any failure: clean up newly uploaded R2 keys, rollback DB.
     */
    final public function store(StoreProductPhotoRequest $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        $uploadedKeys = [];

        // Phase 1 — upload all images to R2 before touching the DB
        $rows = [];
        try {
            foreach ($request->photos as $index => $photo) {
                $name    = Str::slug($product->slug . '-' . Carbon::now()->toDayDateTimeString() . '-' . random_int(10000, 99999));
                $prefix  = 'products/' . $name;
                $keys    = ImageUploadManager::upload(
                    $photo['photo'],
                    $prefix,
                    null,
                    ProductPhoto::PHOTO_WIDTH,
                    ProductPhoto::PHOTO_HEIGHT,
                    ProductPhoto::PHOTO_THUMB_WIDTH,
                    ProductPhoto::PHOTO_THUMB_HEIGHT,
                );
                $uploadedKeys[] = $keys['full'];
                $uploadedKeys[] = $keys['thumb'];

                $rows[] = [
                    'product_id' => $id,
                    'photo'      => $keys['thumb'],
                    'photo_full' => $keys['full'],
                    'is_primary' => $photo['is_primary'] ?? 0,
                    'position'   => $photo['serial'] ?? $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to upload image: ' . $e->getMessage()], 500);
        }

        // Phase 2 — persist to DB; rollback + clean R2 on failure
        try {
            DB::beginTransaction();

            // If any of the new photos is marked primary, clear existing primary flag
            $hasNewPrimary = collect($rows)->contains('is_primary', 1);
            if ($hasNewPrimary) {
                ProductPhoto::where('product_id', $id)->update(['is_primary' => 0]);
            }

            DB::table('product_photos')->insert($rows);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            foreach ($uploadedKeys as $key) ImageUploadManager::deleteKey($key);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to save photo records'], 500);
        }

        CacheService::clearProductCaches();

        return response()->json(['status' => 'success', 'message' => 'Photos uploaded successfully']);
    }

    /**
     * Return a single photo record with CDN URLs.
     */
    public function show(ProductPhoto $photo): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $this->formatPhoto($photo),
        ]);
    }

    /**
     * Update photo metadata (alt text, position). No re-upload.
     */
    public function update(UpdateProductPhotoRequest $request, ProductPhoto $photo): JsonResponse
    {
        $photo->update($request->only(['alt_text', 'position']));

        return response()->json(['status' => 'success', 'message' => 'Photo updated']);
    }

    /**
     * Delete a photo: DB first, R2 after (a missing file < a missing record).
     */
    final public function destroy(ProductPhoto $photo): JsonResponse
    {
        $thumbKey   = $photo->photo;
        $fullKey    = $photo->photo_full;
        $wasPrimary = (bool) $photo->is_primary;
        $productId  = $photo->product_id;

        $photo->delete();

        // Auto-promote the lowest-position remaining photo when the primary is removed.
        if ($wasPrimary) {
            $next = ProductPhoto::where('product_id', $productId)
                ->orderBy('position')
                ->orderBy('id')
                ->first();
            if ($next) {
                $next->update(['is_primary' => 1]);
            }
        }

        if ($fullKey)  ImageUploadManager::deleteKey($fullKey);
        if ($thumbKey) ImageUploadManager::deleteKey($thumbKey);

        CacheService::clearProductCaches();

        return response()->json(['status' => 'success', 'message' => 'Photo deleted']);
    }

    /**
     * Set a photo as the primary image for its product.
     * Clears is_primary on all other photos for that product in a single transaction.
     */
    public function setPrimary(ProductPhoto $photo): JsonResponse
    {
        DB::transaction(function () use ($photo) {
            ProductPhoto::where('product_id', $photo->product_id)->update(['is_primary' => 0]);
            $photo->update(['is_primary' => 1]);
        });

        CacheService::clearProductCaches();

        return response()->json(['status' => 'success', 'message' => 'Primary photo updated']);
    }

    /**
     * Bulk reorder: accepts [{ id, position }] and updates all in one transaction.
     */
    public function reorder(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'photos'           => 'required|array|min:1',
            'photos.*.id'      => 'required|integer|exists:product_photos,id',
            'photos.*.position'=> 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request, $productId) {
            foreach ($request->photos as $item) {
                ProductPhoto::where('id', $item['id'])
                    ->where('product_id', $productId)
                    ->update(['position' => $item['position']]);
            }
        });

        CacheService::clearProductCaches();

        return response()->json(['status' => 'success', 'message' => 'Photos reordered']);
    }

    private function formatPhoto(ProductPhoto $photo): array
    {
        return [
            'id'         => $photo->id,
            'product_id' => $photo->product_id,
            'photo'      => ImageUploadManager::url($photo->photo),
            'photo_full' => ImageUploadManager::url($photo->photo_full),
            'is_primary' => (bool) $photo->is_primary,
            'alt_text'   => $photo->alt_text,
            'position'   => $photo->position,
        ];
    }
}
