<?php

namespace App\Manager;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

/**
 * Centralised image upload manager — Cloudflare R2 backed.
 *
 * All images are stored on R2 via Laravel Storage (disk: 'r2').
 * Stored values are relative R2 keys (e.g. "products/abc/full_xyz.webp").
 * Never store full URLs in the DB — use url() / urls() at read time.
 *
 * Two sizes produced per upload:
 *   full  → {prefix}/full_{slug}.webp
 *   thumb → {prefix}/thumb_{slug}.webp
 */
class ImageUploadManager
{
    public const DISK = 'r2';

    public const DEFAULT_FULL_WIDTH   = 1200;
    public const DEFAULT_FULL_HEIGHT  = 1200;
    public const DEFAULT_THUMB_WIDTH  = 400;
    public const DEFAULT_THUMB_HEIGHT = 400;
    public const QUALITY = 82;

    /**
     * Upload a base64 or raw image to R2, producing full + thumb.
     * Returns array with 'full' and 'thumb' R2 keys.
     *
     * @param  string      $input        Base64 data URI or raw binary
     * @param  string      $prefix       R2 path prefix e.g. "products/uuid123"
     * @param  string|null $existingKey  Old full key to delete (thumb derived automatically)
     * @return array{full: string, thumb: string}
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB raw binary

    public static function upload(
        string  $input,
        string  $prefix,
        ?string $existingKey = null,
        int     $fullWidth   = self::DEFAULT_FULL_WIDTH,
        int     $fullHeight  = self::DEFAULT_FULL_HEIGHT,
        int     $thumbWidth  = self::DEFAULT_THUMB_WIDTH,
        int     $thumbHeight = self::DEFAULT_THUMB_HEIGHT,
    ): array {
        $binary = static::decodeToBinary($input);

        $slug     = Str::random(12);
        $fullKey  = "{$prefix}/full_{$slug}.webp";
        $thumbKey = "{$prefix}/thumb_{$slug}.webp";

        try {
            Storage::disk(self::DISK)->put(
                $fullKey,
                (string) Image::make($binary)->fit($fullWidth, $fullHeight)->encode('webp', self::QUALITY),
                'public'
            );
            Storage::disk(self::DISK)->put(
                $thumbKey,
                (string) Image::make($binary)->fit($thumbWidth, $thumbHeight)->encode('webp', self::QUALITY),
                'public'
            );
        } catch (\Throwable $e) {
            static::deleteKey($fullKey);
            static::deleteKey($thumbKey);
            throw $e;
        }

        // Delete old keys only after new ones are confirmed uploaded
        if ($existingKey) {
            static::deleteKey($existingKey);
            $oldThumb = static::deriveThumbKey($existingKey);
            if ($oldThumb) {
                static::deleteKey($oldThumb);
            }
        }

        return ['full' => $fullKey, 'thumb' => $thumbKey];
    }

    /**
     * Upload a single-size image (avatars, NID photos, etc).
     * Returns the R2 key.
     */
    public static function uploadFromBase64(
        string  $input,
        string  $keyPath,
        ?string $existingKey = null,
        int     $width       = self::DEFAULT_THUMB_WIDTH,
        int     $height      = self::DEFAULT_THUMB_HEIGHT,
    ): string {
        $binary = static::decodeToBinary($input);
        $key    = $keyPath . '.webp';

        try {
            Storage::disk(self::DISK)->put(
                $key,
                (string) Image::make($binary)->fit($width, $height)->encode('webp', self::QUALITY),
                'public'
            );
        } catch (\Throwable $e) {
            static::deleteKey($key);
            throw $e;
        }

        // Delete old key only after new one is confirmed uploaded
        if ($existingKey) {
            static::deleteKey($existingKey);
        }

        return $key;
    }

    /**
     * Decode a base64 data URI or raw binary string, validate MIME type and size.
     *
     * @throws \InvalidArgumentException
     */
    private static function decodeToBinary(string $input): string
    {
        // Strip data URI prefix: "data:image/jpeg;base64,..."
        if (str_contains($input, ';base64,')) {
            $input = substr($input, strpos($input, ';base64,') + 8);
        }

        // Only decode if it looks like base64 (no null bytes)
        if (! str_contains($input, "\0")) {
            $decoded = base64_decode($input, strict: false);
            if ($decoded !== false && strlen($decoded) > 0) {
                $input = $decoded;
            }
        }

        if (strlen($input) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image exceeds maximum allowed size of 10 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($input);
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid image type: {$mime}.");
        }

        return $input;
    }

    /**
     * Delete a key from R2. Silent no-op if key doesn't exist.
     */
    public static function deleteKey(string $key): void
    {
        if ($key && Storage::disk(self::DISK)->exists($key)) {
            Storage::disk(self::DISK)->delete($key);
        }
    }

    /**
     * Get public CDN URL for a stored key.
     */
    public static function url(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        return Storage::disk(self::DISK)->url($key);
    }

    /**
     * Returns logo + logo_full URL pair matching the API contract.
     */
    public static function urls(?string $thumbKey, ?string $fullKey): array
    {
        return [
            'logo'      => static::url($thumbKey),
            'logo_full' => static::url($fullKey),
        ];
    }

    // -------------------------------------------------------------------------
    // Legacy shims — keep existing controllers working during migration.
    // Each caller should be updated to use upload() directly over time.
    // -------------------------------------------------------------------------

    /** @deprecated  Migrate callers to upload(). Returns the full key. */
    public static function processImageUpload(
        string  $file,
        string  $name,
        string  $prefix,
        int     $width,
        int     $height,
        ?string $prefixThumb  = null,
        int     $thumbWidth   = 0,
        int     $thumbHeight  = 0,
        ?string $existingKey  = null,
    ): string {
        $result = static::upload(
            $file,
            rtrim($prefix, '/'),
            $existingKey,
            $width,
            $height,
            $thumbWidth  ?: self::DEFAULT_THUMB_WIDTH,
            $thumbHeight ?: self::DEFAULT_THUMB_HEIGHT,
        );

        return $result['full'];
    }

    /** @deprecated  Deletes from R2 if key looks like an R2 path; local files ignored. */
    public static function deletePhoto(string $path, string $img): void
    {
        if ($img && ! str_starts_with($img, '/') && ! str_contains($img, 'public/')) {
            static::deleteKey($img);
        }
    }

    /** @deprecated  Use url() instead. */
    public static function prepareImageUrl(string $path, ?string $image): string
    {
        if (empty($image)) {
            return '';
        }

        if (str_starts_with($image, 'http')) {
            return $image;
        }

        return static::url($image) ?? '';
    }

    // -------------------------------------------------------------------------

    private static function deriveThumbKey(string $fullKey): ?string
    {
        return str_contains($fullKey, '/full_')
            ? str_replace('/full_', '/thumb_', $fullKey)
            : null;
    }
}
