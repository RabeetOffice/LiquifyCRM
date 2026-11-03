<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Core\Support\SafeSvg;
use App\Modules\Sites\Enums\SitePlatform;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The favicon of a website (fetched from the site itself, largest icon wins)
 * and its platform badge, custom PHP or WordPress, until a person chooses
 * the type. Not final so tests can replace it.
 */
class SiteIdentity
{
    private const EXTENSIONS = [
        'image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/svg+xml' => 'svg',
        'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif',
    ];

    /** Called with the homepage HTML the uptime check already downloaded. */
    public function refresh(Site $site, string $html): void
    {
        $changes = [];
        $platform = $this->platform($html);

        // A type a person chose stays, and so does an Elementor form: that one decides how leads arrive.
        if ($site->platform !== SitePlatform::Elementor && ! PlatformChoice::isChosen($site) && $platform !== $site->platform) {
            $changes['platform'] = $platform;
        }

        if ($site->favicon_path === null) {
            $path = $this->storeFavicon($site, $html);

            if ($path !== null) {
                $changes['favicon_path'] = $path;
            }
        }

        if ($changes !== []) {
            $site->forceFill($changes)->save();
        }
    }

    /**
     * Custom PHP or WordPress, guessed from the homepage. A picture under
     * wp-content/uploads proves nothing: a PHP website rebuilt from an old
     * WordPress one keeps linking to them (UK Publishing House, 3 Nov 2026).
     * Elementor is never guessed, because it changes how leads arrive.
     */
    public function platform(string $html): SitePlatform
    {
        return preg_match('~wp-content/(themes|plugins)/|wp-includes/|name="generator" content="WordPress|api\.w\.org~i', $html)
            ? SitePlatform::WordPress
            : SitePlatform::Php;
    }

    /** Icon addresses found in the page, best first, with /favicon.ico as the fallback. */
    public function iconCandidates(Site $site, string $html): array
    {
        $icons = [];

        if (preg_match_all('~<link\b[^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (! preg_match('~rel=["\']?([^"\'>]*icon[^"\'>]*)~i', $tag, $rel) || ! preg_match('~href=["\']?([^"\'\s>]+)~i', $tag, $href)) {
                    continue;
                }

                $size = preg_match('~sizes=["\']?(\d+)x\d+~i', $tag, $sizes) ? (int) $sizes[1] : 0;
                $apple = str_contains(mb_strtolower($rel[1]), 'apple') ? 180 : 0;

                $icons[] = ['url' => $this->absolute($site, html_entity_decode($href[1])), 'size' => max($size, $apple, 16)];
            }
        }

        usort($icons, fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return array_values(array_unique([...array_column($icons, 'url'), $site->pageUrl('/favicon.ico')]));
    }

    private function storeFavicon(Site $site, string $html): ?string
    {
        foreach (array_slice($this->iconCandidates($site, $html), 0, 3) as $url) {
            try {
                $response = Http::timeout(10)->connectTimeout(5)->withUserAgent('LiquifyCRM')->get($url);
            } catch (Throwable) {
                continue;
            }

            $type = mb_strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $extension = self::EXTENSIONS[$type] ?? null;
            $body = $response->body();

            if (! $response->successful() || $extension === null || $body === '' || strlen($body) > 512 * 1024) {
                continue;
            }

            // The file is served from our own address, so an SVG must be a drawing and nothing more.
            if ($extension === 'svg' && ! SafeSvg::isDrawing($body)) {
                continue;
            }

            $path = 'sites/favicons/'.$site->getKey().'.'.$extension;
            Storage::disk('public')->put($path, $body);

            return $path;
        }

        return null;
    }

    private function absolute(Site $site, string $href): string
    {
        return match (true) {
            (bool) preg_match('~^https?://~i', $href) => $href,
            str_starts_with($href, '//') => 'https:'.$href,
            default => $site->pageUrl($href),
        };
    }
}
