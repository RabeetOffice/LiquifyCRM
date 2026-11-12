<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Brands\Support\BrandWebsite;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The colours a website is dressed in, read from its home page and its own style sheets (user request, 12 Nov 2026:
 * "widget style according to website colour automatically"). Every colour the page uses is weighed by where it is
 * used: the theme-color tag, colour variables with a brand-like name and the backgrounds of buttons, headers and
 * footers count most, the colour of plain text least. Greys, near white and near black say nothing about a brand and
 * are left out; colours close to each other count as one. The heaviest dark colour becomes the chat's main colour (the
 * launcher and the header), the heaviest colour that stands apart from it the accent (the icon's circle, the send
 * button), which is how the brands' own websites draw their chat buttons.
 */
final class WebsiteLook
{
    /** Style sheets of well-known libraries carry their own colours, not the brand's. */
    private const LIBRARIES = '/(bootstrap|font-?awesome|fontawesome|swiper|slick|owl|aos|animate|jquery|select2|lightbox|fancybox|magnific|flatpickr|intl-tel|splide|glide|tailwind|normalize|reset|wp-includes|elementor-icons|dashicons)/i';

    private const MAX_SHEETS = 4;

    private const MAX_BYTES = 800_000;

    public function __construct(private readonly BrandWebsite $website) {}

    /**
     * @return array{color: ?string, accent: ?string, palette: list<string>, error: ?string}
     */
    public function read(string $domain): array
    {
        $domain = strtolower(trim($domain));

        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1) {
            return ['color' => null, 'accent' => null, 'palette' => [], 'error' => 'List the website first.'];
        }

        /** @var array{color: ?string, accent: ?string, palette: list<string>, error: ?string} */
        return Cache::remember('chat-website-look:'.$domain, 600, function () use ($domain): array {
            $url = 'https://'.$domain;
            $page = $this->website->page($url);

            if ($page['html'] === null) {
                return ['color' => null, 'accent' => null, 'palette' => [], 'error' => 'We could not open '.$domain.'. '.($page['error'] ?? '')];
            }

            return $this->fromHtml($page['html'], $this->sheets($url, $page['html']));
        });
    }

    /**
     * The look of a page whose HTML and style sheets are already in hand.
     *
     * @param  list<string>  $sheets
     * @return array{color: ?string, accent: ?string, palette: list<string>, error: ?string}
     */
    public function fromHtml(string $html, array $sheets = []): array
    {
        /** @var array<string, float> $weights */
        $weights = [];

        if (preg_match('~<meta\b[^>]*name=["\']theme-color["\'][^>]*content=["\']([^"\']+)~i', $html, $match)
            || preg_match('~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']theme-color["\']~i', $html, $match)) {
            $this->add($weights, $match[1], 10.0);
        }

        preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $styles);

        foreach ([...$styles[1], ...$sheets] as $css) {
            $this->weighCss($weights, $css);
        }

        // Inline styles on the page's own elements (a coloured button drawn with style="").
        preg_match_all('~<(a|button|header|footer|nav|section|div)\b[^>]*\bstyle=["\']([^"\']+)["\']~i', $html, $inline, PREG_SET_ORDER);

        foreach ($inline as $tag) {
            $this->weighDeclarations($weights, $tag[2], in_array(strtolower($tag[1]), ['a', 'button', 'header', 'footer', 'nav'], true) ? 'btn' : '');
        }

        $palette = $this->cluster($weights);

        if ($palette === []) {
            return ['color' => null, 'accent' => null, 'palette' => [], 'error' => 'The website uses no colour we could tell apart from black, white and grey.'];
        }

        $hexes = array_keys($palette);
        $main = null;

        foreach ($hexes as $hex) {
            if (self::lightness($hex) < 0.42) {
                $main = $hex;
                break;
            }
        }

        $main ??= $hexes[0];
        // The accent is a lively colour of its own: a pale tint of the background is not one.
        $accent = null;

        foreach ($hexes as $hex) {
            [, $s, $l] = self::hsl($hex);

            if ($hex !== $main && $s >= 0.4 && $l >= 0.3 && $l <= 0.78 && (self::hueGap($hex, $main) >= 30 || abs($l - self::lightness($main)) >= 0.25)) {
                $accent = $hex;
                break;
            }
        }

        return ['color' => $main, 'accent' => $accent ?? $main, 'palette' => array_slice($hexes, 0, 6), 'error' => null];
    }

    /** @return list<string> the website's own style sheets, without the libraries */
    private function sheets(string $url, string $html): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $links = [];

        preg_match_all('~<link\b[^>]*>~i', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('~rel=["\']?stylesheet~i', $tag) !== 1 || preg_match('~href=["\']([^"\']+)["\']~i', $tag, $href) !== 1) {
                continue;
            }

            $address = $this->website->absolute($url, html_entity_decode($href[1]));
            $sheetHost = (string) parse_url($address, PHP_URL_HOST);
            $own = $sheetHost === $host || str_ends_with($sheetHost, '.'.preg_replace('/^www\./', '', $host));

            if ($own && str_starts_with($address, 'https://') && preg_match(self::LIBRARIES, $address) !== 1) {
                $links[] = $address;
            }
        }

        $links = array_slice(array_values(array_unique($links)), 0, self::MAX_SHEETS);

        if ($links === []) {
            return [];
        }

        try {
            $responses = $this->website->http()->pool(fn (Pool $pool): array => array_map(
                fn (string $link) => $pool->timeout(6)->connectTimeout(3)->withUserAgent('Mozilla/5.0 (compatible; LiquifyCRM)')->get($link),
                $links,
            ));
        } catch (Throwable) {
            return [];
        }

        $sheets = [];

        foreach ($responses as $response) {
            if ($response instanceof Response && $response->successful() && strlen($response->body()) <= self::MAX_BYTES) {
                $sheets[] = $response->body();
            }
        }

        return $sheets;
    }

    /** @param  array<string, float>  $weights */
    private function weighCss(array &$weights, string $css): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

        if (preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($rules as $rule) {
            $selector = strtolower(trim($rule[1]));

            // Hover and focus states repeat the colour of the thing itself; dark-mode copies are not the website's face.
            if (str_contains($selector, ':hover') || str_contains($selector, ':focus') || str_contains($selector, '@')) {
                continue;
            }

            $kind = preg_match('/(btn|button|cta|nav|header|footer|hero|banner|badge|pill|chip|primary|brand)/', $selector) === 1 ? 'btn' : '';
            $this->weighDeclarations($weights, $rule[2], $kind);
        }
    }

    /** @param  array<string, float>  $weights */
    private function weighDeclarations(array &$weights, string $declarations, string $kind): void
    {
        foreach (explode(';', $declarations) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));

            if ($value === '' || $property === '') {
                continue;
            }

            $weight = match (true) {
                str_starts_with($property, '--') => preg_match('/(primary|brand|accent|main|theme|navy|ink|secondary|highlight|cta|green|blue|red|gold|orange|purple)/', $property) === 1 && preg_match('/(swiper|bs-|scalar|wp-|tw-)/', $property) !== 1 ? 6.0 : 1.5,
                str_starts_with($property, 'background') => $kind === 'btn' ? 5.0 : 2.0,
                $property === 'color' => $kind === 'btn' ? 1.5 : 1.0,
                str_starts_with($property, 'border') || $property === 'fill' => 0.5,
                default => 0.0,
            };

            if ($weight > 0) {
                $this->add($weights, $value, $weight);
            }
        }
    }

    /** @param  array<string, float>  $weights */
    private function add(array &$weights, string $value, float $weight): void
    {
        preg_match_all('/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)/i', $value, $found);

        foreach ($found[0] as $color) {
            $hex = self::hex($color);

            if ($hex !== null && self::saysSomething($hex)) {
                $weights[$hex] = ($weights[$hex] ?? 0.0) + $weight;
            }
        }
    }

    /**
     * Colours close to each other are one colour, under the name of the one used most.
     *
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function cluster(array $weights): array
    {
        arsort($weights);
        $groups = [];

        foreach ($weights as $hex => $weight) {
            foreach ($groups as $leader => $total) {
                if (self::distance($hex, $leader) < 48) {
                    $groups[$leader] = $total + $weight;

                    continue 2;
                }
            }

            $groups[$hex] = $weight;
        }

        arsort($groups);

        return $groups;
    }

    public static function hex(string $value): ?string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/', $value, $rgb)) {
            if (isset($rgb[4]) && (float) $rgb[4] < 0.6) {
                return null;
            }

            return sprintf('#%02x%02x%02x', min(255, (int) $rgb[1]), min(255, (int) $rgb[2]), min(255, (int) $rgb[3]));
        }

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $short)) {
            return '#'.$short[1].$short[1].$short[2].$short[2].$short[3].$short[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /** Not grey, not near white, not near black. */
    private static function saysSomething(string $hex): bool
    {
        [$h, $s, $l] = self::hsl($hex);

        return $s >= 0.2 && $l >= 0.08 && $l <= 0.9;
    }

    public static function lightness(string $hex): float
    {
        return self::hsl($hex)[2];
    }

    private static function hueGap(string $a, string $b): float
    {
        $gap = abs(self::hsl($a)[0] - self::hsl($b)[0]);

        return min($gap, 360 - $gap);
    }

    private static function distance(string $a, string $b): float
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);

        return sqrt((($r1 - $r2) ** 2) + (($g1 - $g2) ** 2) + (($b1 - $b2) ** 2));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** @return array{0: float, 1: float, 2: float} hue in degrees, saturation and lightness from 0 to 1 */
    private static function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c): float => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod((($g - $b) / $d) + ($g < $b ? 6 : 0), 6),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h * 60, $s, $l];
    }
}
