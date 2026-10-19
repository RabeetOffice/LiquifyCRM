<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Brands\Models\Brand;
use Illuminate\Support\Facades\Storage;

/**
 * The brand's mark for its invoice PDF (19 Oct 2026). DOMPDF reads no web
 * address and draws only some pictures, so the picture goes in as a data
 * address: the logo when it is a picture a PDF draws (WebP and AVIF become
 * PNG), an SVG logo only when it is plain enough (gradients, styles and
 * masks come out black), else the brand's favicon when it is big enough,
 * else nothing and the invoice shows the brand's initials in its colour.
 */
final class InvoiceLogo
{
    /** @return array{src: string, wide: bool}|null */
    public static function for(Brand $brand): ?array
    {
        foreach ([$brand->logo_path, $brand->favicon_path] as $index => $path) {
            $picture = $path ? self::picture((string) $path, $index === 1) : null;

            if ($picture !== null) {
                return $picture;
            }
        }

        return null;
    }

    /** @return array{src: string, wide: bool}|null */
    private static function picture(string $path, bool $icon): ?array
    {
        $disk = Storage::disk('public');
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true) || ! $disk->exists($path)) {
            return null;
        }

        $content = (string) $disk->get($path);

        if ($content === '') {
            return null;
        }

        if ($extension === 'svg') {
            return ! $icon && self::plainSvg($content)
                ? ['src' => 'data:image/svg+xml;base64,'.base64_encode($content), 'wide' => self::svgIsWide($content)]
                : null;
        }

        if (in_array($extension, ['webp', 'avif'], true)) {
            $content = self::asPng($content);

            if ($content === null) {
                return null;
            }

            $extension = 'png';
        }

        $size = @getimagesizefromstring($content);

        // A favicon of 32 pixels would print as a blur.
        if ($size === false || ($icon && min($size[0], $size[1]) < 96)) {
            return null;
        }

        $type = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return ['src' => 'data:'.$type.';base64,'.base64_encode($content), 'wide' => $size[0] > $size[1] * 1.6];
    }

    /** No gradients, styles, masks, filters or embedded pictures: what DOMPDF draws as the website does. */
    private static function plainSvg(string $svg): bool
    {
        return ! preg_match('/url\(#|<style|\sclass=|<filter|<mask|<pattern|<image|<foreignObject|<script/i', $svg);
    }

    private static function svgIsWide(string $svg): bool
    {
        if (preg_match('/viewBox=["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $box) && (float) $box[2] > 0) {
            return (float) $box[1] > (float) $box[2] * 1.6;
        }

        return true;
    }

    private static function asPng(string $content): ?string
    {
        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($content) : false;

        if ($image === false) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return $png !== '' ? $png : null;
    }
}
