<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A profile photo is shown at a few dozen pixels, in lists, beside every note and in the sidebar, so the file a
 * person uploads (often one or two megabytes from a phone) is made small before it is kept: at most 512 pixels on
 * its longer side, as a WebP (a JPEG where the server cannot write WebP).
 */
final class SmallPhoto
{
    /** The longer side of a kept photo, in pixels. */
    public const SIDE = 512;

    /** A stored photo above this many bytes is worth making smaller. */
    public const HEAVY = 300 * 1024;

    /**
     * The picture made small, or null when it is not a picture the server can read (keep the original then).
     *
     * @return array{bytes: string, extension: string}|null
     */
    public static function shrink(string $bytes): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::SIDE / max(1, max($width, $height)));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $small = imagecreatetruecolor($newWidth, $newHeight);
        // A see-through picture stays see-through.
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($small, null, 82);

        if (! $webp) {
            // JPEG has no see-through: put the picture on white.
            $flat = imagecreatetruecolor($newWidth, $newHeight);
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $small, 0, 0, 0, 0, $newWidth, $newHeight);
            imagejpeg($flat, null, 85);
        }

        $out = (string) ob_get_clean();

        // Never swap a photo for a larger file or for nothing.
        if ($out === '' || strlen($out) >= strlen($bytes)) {
            return null;
        }

        return ['bytes' => $out, 'extension' => $webp ? 'webp' : 'jpg'];
    }

    /** Keeps the bytes of a profile photo on the public disk, made small when possible; returns the path. */
    public static function store(string $bytes, string $originalExtension): string
    {
        $small = self::shrink($bytes);
        $path = 'avatars/'.Str::random(40).'.'.($small['extension'] ?? strtolower($originalExtension));

        Storage::disk('public')->put($path, $small['bytes'] ?? $bytes);

        return $path;
    }

    /** Photos kept before this existed: the heavy ones are made small. Returns how many. */
    public static function shrinkStored(): int
    {
        $disk = Storage::disk('public');
        $done = 0;

        User::query()->whereNotNull('avatar_path')->get(['id', 'avatar_path'])->each(function (User $user) use ($disk, &$done): void {
            $old = (string) $user->avatar_path;

            if (! $disk->exists($old) || $disk->size($old) <= self::HEAVY) {
                return;
            }

            $small = self::shrink((string) $disk->get($old));

            if ($small === null) {
                return;
            }

            $path = 'avatars/'.Str::random(40).'.'.$small['extension'];
            $disk->put($path, $small['bytes']);
            $user->forceFill(['avatar_path' => $path])->saveQuietly();
            $disk->delete($old);
            $done++;
        });

        return $done;
    }
}
