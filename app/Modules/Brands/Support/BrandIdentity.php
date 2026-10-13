<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

use App\Modules\Brands\Models\Brand;
use Illuminate\Support\Facades\Storage;
use League\ColorExtractor\Color;
use League\ColorExtractor\ColorExtractor;
use League\ColorExtractor\Palette;
use Throwable;

/**
 * Brings a brand's look in from its website: the favicon (largest icon
 * wins), the main logo, and the brand's colour from the theme-color tag and
 * web manifest or, when the site has none, from the logo or icon itself.
 * Pictures are saved locally. Not final so tests can replace it.
 */
class BrandIdentity
{
    private const EXTENSIONS = [
        'image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/svg+xml' => 'svg',
        'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif',
    ];

    /** A picture the website gave, as against one somebody uploaded: only these are replaced. */
    private const FETCHED_LOGO = 'brands/logos/fetched-';

    private const FETCHED_ICON = 'brands/favicons/fetched-';

    public function __construct(private readonly BrandWebsite $website) {}

    /**
     * `$withLogo` is off for the weekly refresh, which only keeps the favicon
     * current. `$fresh` reads the website again instead of the page kept from
     * a moment ago.
     *
     * @return array{favicon: bool, logo: bool, colors: array<string, string>, error: ?string}
     */
    public function fetch(Brand $brand, bool $withColors = true, bool $withLogo = true, bool $fresh = false): array
    {
        $url = rtrim((string) $brand->website, '/');

        if ($url === '') {
            return ['favicon' => false, 'logo' => false, 'colors' => [], 'error' => 'Enter the brand website first.'];
        }

        $page = $this->website->page($url, $fresh);

        if ($page['html'] === null) {
            return ['favicon' => false, 'logo' => false, 'colors' => [], 'error' => $page['error']];
        }

        $html = $page['html'];
        $icon = $this->storeIcon($brand, $url, $html);
        $logo = $withLogo ? $this->storeLogo($brand, $url, $html) : null;
        $colors = $withColors ? $this->colors($url, $html, array_values(array_filter([$logo ?? $brand->logo_path, $icon ?? $brand->favicon_path]))) : [];

        $brand->forceFill(array_filter([
            'favicon_path' => $icon,
            'logo_path' => $logo,
            'colors' => $colors !== [] ? [...($brand->colors ?? []), ...$colors] : null,
            'identity_fetched_at' => now(),
        ], fn (mixed $value): bool => $value !== null))->save();

        return ['favicon' => $icon !== null, 'logo' => $logo !== null, 'colors' => $colors, 'error' => null];
    }

    /**
     * The brand's own colour: theme-color and the manifest first, the main
     * colour of its logo or icon as the fallback. It is kept as the second
     * colour, and the main colour is white: a logo is drawn for a white
     * page, and on a tile of its own colour it disappears.
     *
     * @param  list<string>  $pictures  saved logo and icon, the better source first
     * @return array<string, string> primary and maybe secondary, as #RRGGBB
     */
    private function colors(string $url, string $html, array $pictures): array
    {
        $found = [];

        if (preg_match('~<meta\b[^>]*name=["\']theme-color["\'][^>]*content=["\']([^"\']+)~i', $html, $match)
            || preg_match('~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']theme-color["\']~i', $html, $match)) {
            $found[] = $this->hex($match[1]);
        }

        if (preg_match('~<link\b[^>]*rel=["\']manifest["\'][^>]*href=["\']([^"\']+)~i', $html, $match)
            || preg_match('~<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']manifest["\']~i', $html, $match)) {
            try {
                $manifest = (array) $this->website->http()->get($this->website->absolute($url, html_entity_decode($match[1])))->json();
                $found[] = $this->hex((string) ($manifest['theme_color'] ?? ''));
                $found[] = $this->hex((string) ($manifest['background_color'] ?? ''));
            } catch (Throwable) {
                // A missing manifest is not a problem.
            }
        }

        // White, black and their neighbours say nothing about a brand.
        $found = array_values(array_unique(array_filter($found, fn (?string $hex): bool => $hex !== null && Brand::colorSaysSomething($hex))));

        foreach ($pictures as $picture) {
            if ($found !== []) {
                break;
            }

            $found = $this->fromImage(Storage::disk('public')->path($picture));
        }

        return $found === [] ? [] : ['primary' => '#FFFFFF', 'secondary' => $found[0]];
    }

    /** @return list<string> the 2 main colours of an image */
    private function fromImage(string $path): array
    {
        if (! function_exists('imagecreatefromstring') || ! in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'gif'], true)) {
            return [];
        }

        try {
            $colors = (new ColorExtractor(Palette::fromFilename($path, Color::fromHexToInt('#FFFFFF'))))->extract(5);
        } catch (Throwable) {
            return [];
        }

        // A logo on a transparent or white background is mostly "white": that is not the brand's colour.
        $hexes = array_map(fn (int $color): string => mb_strtoupper(Color::fromIntToHex($color)), $colors);

        return array_slice(array_values(array_filter($hexes, Brand::colorSaysSomething(...))), 0, 2);
    }

    private function storeIcon(Brand $brand, string $url, string $html): ?string
    {
        $current = (string) $brand->favicon_path;

        if ($current !== '' && ! str_starts_with($current, self::FETCHED_ICON)) {
            return null;
        }

        foreach (array_slice($this->website->iconCandidates($url, $html), 0, 3) as $candidate) {
            $picture = $this->download($candidate, 1024 * 1024);

            if ($picture === null) {
                continue;
            }

            $path = self::FETCHED_ICON.$brand->getKey().'.'.$picture['extension'];
            Storage::disk('public')->put($path, $picture['body']);

            return $path;
        }

        return null;
    }

    /**
     * The main logo, for the brand's own screens and, later, its invoices and
     * contracts. Like the favicon, one somebody uploaded is never replaced.
     */
    private function storeLogo(Brand $brand, string $url, string $html): ?string
    {
        $current = (string) $brand->logo_path;

        if ($current !== '' && ! str_starts_with($current, self::FETCHED_LOGO)) {
            return null;
        }

        foreach (array_slice($this->website->logoCandidates($url, $html), 0, 3) as $candidate) {
            $picture = $this->download($candidate, 2 * 1024 * 1024);

            if ($picture === null || $picture['extension'] === 'ico' || $this->madeForDark($picture['body'], $picture['extension'])) {
                continue;
            }

            // A document (PDF) cannot show WebP or AVIF, so those are kept as PNG.
            if (in_array($picture['extension'], ['webp', 'avif'], true) && ($png = $this->asPng($picture['body'])) !== null) {
                $picture = ['body' => $png, 'extension' => 'png'];
            }

            $path = self::FETCHED_LOGO.$brand->getKey().'.'.$picture['extension'];

            if ($current !== '' && $current !== $path) {
                Storage::disk('public')->delete($current);
            }

            Storage::disk('public')->put($path, $picture['body']);

            return $path;
        }

        return null;
    }

    /**
     * A picture from the website, or null when the address gives something
     * else, too much, or an SVG that carries a script.
     *
     * @return array{body: string, extension: string}|null
     */
    private function download(string $address, int $limit): ?array
    {
        try {
            $response = $this->website->http()->get($address);
        } catch (Throwable) {
            return null;
        }

        $type = mb_strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extension = self::EXTENSIONS[$type] ?? null;
        $body = $response->body();

        if (! $response->successful() || $extension === null || $body === '' || strlen($body) > $limit) {
            return null;
        }

        // The file is served from our own address, so an SVG must be a drawing and nothing more.
        if ($extension === 'svg' && preg_match('~<script|\son\w+\s*=|javascript:|<foreignObject|<iframe|<embed|<object~i', $body)) {
            return null;
        }

        return ['body' => $body, 'extension' => $extension];
    }

    /**
     * A white logo on a see-through background is made for a dark page: on
     * the white of an invoice it cannot be seen, so it is not taken.
     */
    private function madeForDark(string $body, string $extension): bool
    {
        if ($extension === 'svg' || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        try {
            $image = imagecreatefromstring($body);

            if ($image === false) {
                return false;
            }

            imagepalettetotruecolor($image);

            $width = imagesx($image);
            $height = imagesy($image);
            $clear = imagecolortransparent($image);
            $seen = $solid = $light = 0;

            // About fifty points each way say enough.
            for ($y = 0; $y < $height; $y += max(1, intdiv($height, 48))) {
                for ($x = 0; $x < $width; $x += max(1, intdiv($width, 48))) {
                    $pixel = (int) imagecolorat($image, $x, $y);
                    $seen++;

                    if ((($pixel >> 24) & 0x7F) > 100 || ($clear >= 0 && ($pixel & 0xFFFFFF) === $clear)) {
                        continue;
                    }

                    $solid++;
                    $light += min(($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF) > 225 ? 1 : 0;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return $solid > 0 && $solid < $seen * 0.9 && $light > $solid * 0.9;
    }

    private function asPng(string $body): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = imagecreatefromstring($body);

            if ($image === false) {
                return null;
            }

            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();

            try {
                imagepng($image);
            } finally {
                $png = (string) ob_get_clean();
            }
        } catch (Throwable) {
            return null;
        }

        return $png !== '' ? $png : null;
    }

    private function hex(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $short)) {
            $value = '#'.$short[1][0].$short[1][0].$short[1][1].$short[1][1].$short[1][2].$short[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? mb_strtoupper($value) : null;
    }
}
