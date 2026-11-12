<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Chat\Http\Controllers\WidgetController;
use App\Modules\Chat\Jobs\LocateVisitor;
use App\Modules\Chat\Models\ChatPageView;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The chat box's launcher on a website tells the server, every half minute, that this browser is there and which
 * page it is on. The page address is kept without the part after "?" (it may carry names, emails or tokens), the title
 * without tags, and where the person came from as a website name only. A new page is a new line in the page trail.
 * What comes back, once, is an invitation the team sent to this browser.
 */
final class RecordPing
{
    /**
     * @param  array{url?: mixed, title?: mixed, referrer?: mixed}  $page
     * @param  array{new_key?: string, ip?: ?string, country?: ?string, device?: ?string, browser?: ?string}  $info
     * @return array{text: string, from: string}|null
     */
    public function handle(ChatWidget $widget, string $vid, array $page, array $info): ?array
    {
        $url = $this->cleanUrl($page['url'] ?? null, $widget);
        $title = $this->cleanTitle($page['title'] ?? null);
        $referrer = $this->cleanReferrer($page['referrer'] ?? null, $url);
        $now = now();

        $session = ChatSession::query()->where('brand_id', $widget->brand_id)->where('vid', $vid)->first();

        $seen = array_filter([
            'chat_widget_id' => $widget->getKey(),
            'country' => $info['country'] ?? null,
            'device' => $info['device'] ?? null,
            'browser' => $info['browser'] ?? null,
            'ip' => LiveChat::geoReady() ? ($info['ip'] ?? null) : null,
        ], fn (mixed $value): bool => $value !== null);

        if ($session === null) {
            // A script making up browser codes would fill Live visitors: an address reports a few new browsers a minute.
            if (isset($info['new_key'])) {
                if (RateLimiter::tooManyAttempts($info['new_key'], WidgetController::NEW_SESSIONS_PER_MINUTE)) {
                    return null;
                }

                RateLimiter::hit($info['new_key'], 60);
            }

            try {
                $session = ChatSession::query()->create([
                    ...$seen,
                    'brand_id' => $widget->brand_id,
                    'vid' => $vid,
                    'current_url' => $url,
                    'current_title' => $title,
                    'referrer' => $referrer,
                    'visit_started_at' => $now,
                    'page_changed_at' => $now,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
                $this->view($session, $url, $title);
                LocateVisitor::soon($session, $session->ip);
            } catch (QueryException) {
                // Two pings of the same browser at the same moment: the other one made it.
                $session = ChatSession::query()->where('brand_id', $widget->brand_id)->where('vid', $vid)->first();
            }

            return $session !== null ? $this->invite($session) : null;
        }

        // Back after a pause is a new visit, with the place it came from this time.
        $returned = $session->last_seen_at === null || $session->last_seen_at->lt($now->copy()->subMinutes(ChatSession::NEW_VISIT_MINUTES));
        $moved = $url !== null && $url !== $session->current_url;
        $placeAgain = isset($seen['ip']) && ($seen['ip'] !== $session->ip || ($returned && $session->city === null));

        $session->forceFill([
            ...$seen,
            'last_seen_at' => $now,
            ...($returned ? ['visits_count' => $session->visits_count + 1, 'visit_started_at' => $now, 'referrer' => $referrer] : []),
            ...($moved || $returned ? ['current_url' => $url ?? $session->current_url, 'current_title' => $title ?? $session->current_title, 'page_changed_at' => $now] : []),
        ])->save();

        if ($moved || ($returned && $url !== null)) {
            $this->view($session, $url, $title);
        }

        if ($placeAgain) {
            LocateVisitor::soon($session, $session->ip);
        }

        return $this->invite($session);
    }

    /**
     * An invitation waiting for this browser is handed over once.
     *
     * @return array{text: string, from: string}|null
     */
    private function invite(ChatSession $session): ?array
    {
        if ($session->invite_text === null || $session->invite_shown_at !== null) {
            return null;
        }

        $session->forceFill(['invite_shown_at' => now()])->save();

        return ['text' => $session->invite_text, 'from' => $session->invite_from ?? 'Our team'];
    }

    private function view(ChatSession $session, ?string $url, ?string $title): void
    {
        if ($url === null) {
            return;
        }

        ChatPageView::query()->create(['chat_session_id' => $session->getKey(), 'brand_id' => $session->brand_id, 'url' => $url, 'title' => $title, 'viewed_at' => now()]);
    }

    /** "https://www.example.com/memoirs?ref=x#top" becomes "https://www.example.com/memoirs"; null when it is not one of the widget's websites. */
    private function cleanUrl(mixed $value, ChatWidget $widget): ?string
    {
        $parts = is_string($value) ? parse_url(trim($value)) : false;

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! $widget->allowsOrigin($parts['scheme'].'://'.$host)) {
            return null;
        }

        return mb_substr($parts['scheme'].'://'.$host.(string) ($parts['path'] ?? '/'), 0, 500);
    }

    private function cleanTitle(mixed $value): ?string
    {
        $title = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($value))) : '';

        return $title !== '' ? mb_substr($title, 0, 190) : null;
    }

    /** Where they came from, as a website name: nothing for a page of the same website. */
    private function cleanReferrer(mixed $value, ?string $url): ?string
    {
        $host = is_string($value) ? strtolower((string) parse_url(trim($value), PHP_URL_HOST)) : '';
        $own = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        $strip = fn (string $name): string => preg_replace('/^www\./', '', $name) ?? $name;

        if ($host === '' || ($own !== '' && $strip($host) === $strip($own))) {
            return null;
        }

        return mb_substr($host, 0, 190);
    }
}
