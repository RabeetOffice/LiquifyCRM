<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

use App\Modules\Brands\Models\Brand;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\ColorExtractor\Color;
use League\ColorExtractor\ColorExtractor;
use League\ColorExtractor\Palette;
use Throwable;

/**
 * Reads a brand's homepage once: the favicon (largest icon wins, saved
 * locally) and the brand colours from the theme-color tag and web manifest,
 * or, when the site has none, the main colours of its icon. Not final so
 * tests can replace it.
 */
class BrandIdentity
{
    private const EXTENSIONS = [
        'image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/svg+xml' => 'svg',
        'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif',
    ];

    /**
     * @return array{favicon: bool, colors: array<string, string>, error: ?string}
     */
    public function fetch(Brand $brand, bool $withColors = true): array
    {
        $url = rtrim((string) $brand->website, '/');

        if ($url === '') {
            return ['favicon' => false, 'colors' => [], 'error' => 'Enter the brand website first.'];
        }

        try {
            $response = $this->http()->get($url.'/');
        } catch (Throwable $exception) {
            return ['favicon' => false, 'colors' => [], 'error' => 'The website could not be reached.'];
        }

        if (! $response->successful()) {
            return ['favicon' => false, 'colors' => [], 'error' => 'The website answered with error '.$response->status().'.'];
        }

        $html = $response->body();
        $icon = $this->storeIcon($brand, $url, $html);
        $colors = $withColors ? $this->colors($url, $html, $icon) : [];

        $brand->forceFill(array_filter([
            'favicon_path' => $icon,
            'colors' => $colors !== [] ? [...($brand->colors ?? []), ...$colors] : null,
            'identity_fetched_at' => now(),
        ], fn (mixed $value): bool => $value !== null))->save();

        return ['favicon' => $icon !== null, 'colors' => $colors, 'error' => null];
    }

    /**
     * The name a website gives itself, for filling in the brand name from its
     * link: og:site_name, then application-name, then the page title without
     * its tagline ("UK Publishing House | Book publishing" becomes
     * "UK Publishing House"). Null when the site cannot be read.
     */
    public function nameFor(string $url): ?string
    {
        try {
            $response = Http::timeout(8)->connectTimeout(4)->withUserAgent('LiquifyCRM')->get(rtrim($url, '/').'/');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = mb_substr($response->body(), 0, 200000);
        $name = null;

        foreach ([
            '~<meta\b[^>]*property=["\']og:site_name["\'][^>]*content=["\']([^"\']+)~i',
            '~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:site_name["\']~i',
            '~<meta\b[^>]*name=["\']application-name["\'][^>]*content=["\']([^"\']+)~i',
            '~<title[^>]*>([^<]+)</title>~i',
        ] as $index => $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $name = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));

                // A page title usually carries a tagline after the name.
                if ($index === 3) {
                    $name = trim((string) preg_split('~\s+[|\-–—:·»]\s+~u', $name)[0]);
                }

                break;
            }
        }

        if ($name === null || $name === '' || in_array(mb_strtolower($name), ['home', 'homepage', 'welcome'], true)) {
            return null;
        }

        return mb_substr($name, 0, 80);
    }

    /**
     * Icon addresses in the page, largest first, then /favicon.ico.
     *
     * @return list<string>
     */
    public function iconCandidates(string $url, string $html): array
    {
        $icons = [];

        if (preg_match_all('~<link\b[^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (! preg_match('~rel=["\']?([^"\'>]*icon[^"\'>]*)~i', $tag, $rel) || ! preg_match('~href=["\']?([^"\'\s>]+)~i', $tag, $href)) {
                    continue;
                }

                $size = preg_match('~sizes=["\']?(\d+)x\d+~i', $tag, $sizes) ? (int) $sizes[1] : 0;
                $apple = str_contains(mb_strtolower($rel[1]), 'apple') ? 180 : 0;

                $icons[] = ['url' => $this->absolute($url, html_entity_decode($href[1])), 'size' => max($size, $apple, 16)];
            }
        }

        usort($icons, fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return array_values(array_unique([...array_column($icons, 'url'), $url.'/favicon.ico']));
    }

    /**
     * theme-color and manifest first; the icon's own colours as the fallback.
     *
     * @return array<string, string> primary and maybe secondary, as #RRGGBB
     */
    private function colors(string $url, string $html, ?string $iconPath): array
    {
        $found = [];

        if (preg_match('~<meta\b[^>]*name=["\']theme-color["\'][^>]*content=["\']([^"\']+)~i', $html, $match)
            || preg_match('~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']theme-color["\']~i', $html, $match)) {
            $found[] = $this->hex($match[1]);
        }

        if (preg_match('~<link\b[^>]*rel=["\']manifest["\'][^>]*href=["\']([^"\']+)~i', $html, $match)
            || preg_match('~<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']manifest["\']~i', $html, $match)) {
            try {
                $manifest = (array) $this->http()->get($this->absolute($url, html_entity_decode($match[1])))->json();
                $found[] = $this->hex((string) ($manifest['theme_color'] ?? ''));
                $found[] = $this->hex((string) ($manifest['background_color'] ?? ''));
            } catch (Throwable) {
                // A missing manifest is not a problem.
            }
        }

        // Pure white or black says nothing about a brand.
        $found = array_values(array_unique(array_filter($found, fn (?string $hex): bool => $hex !== null && ! in_array($hex, ['#FFFFFF', '#000000'], true))));

        if ($found === [] && $iconPath !== null) {
            $found = $this->fromImage(Storage::disk('public')->path($iconPath));
        }

        return array_filter(['primary' => $found[0] ?? null, 'secondary' => $found[1] ?? null]);
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
        foreach (array_slice($this->iconCandidates($url, $html), 0, 3) as $candidate) {
            try {
                $response = $this->http()->get($candidate);
            } catch (Throwable) {
                continue;
            }

            $type = mb_strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $extension = self::EXTENSIONS[$type] ?? null;
            $body = $response->body();

            if (! $response->successful() || $extension === null || $body === '' || strlen($body) > 1024 * 1024) {
                continue;
            }

            $path = 'brands/favicons/fetched-'.$brand->getKey().'.'.$extension;
            Storage::disk('public')->put($path, $body);

            return $path;
        }

        return null;
    }

    private function hex(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $short)) {
            $value = '#'.$short[1][0].$short[1][0].$short[1][1].$short[1][1].$short[1][2].$short[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? mb_strtoupper($value) : null;
    }

    private function absolute(string $url, string $href): string
    {
        return match (true) {
            (bool) preg_match('~^https?://~i', $href) => $href,
            str_starts_with($href, '//') => 'https:'.$href,
            default => $url.'/'.ltrim($href, '/'),
        };
    }

    private function http(): PendingRequest
    {
        return Http::timeout(12)->connectTimeout(6)->withUserAgent('LiquifyCRM');
    }
}
