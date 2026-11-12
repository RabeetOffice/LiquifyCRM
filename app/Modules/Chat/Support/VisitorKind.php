<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Chat\Models\ChatSession;

/**
 * Real person or bot (user request, 12 Nov 2026). A person scrolls, taps, clicks, types or moves the mouse; the chat button
 * notes the first time (`chat_sessions.interacted_at`), and writing in the chat counts too. A browser from a VPN, proxy or
 * data-centre address that has done none of it after a little while is probably a program (crawlers, page testers,
 * previews). Anything else is not decided yet.
 */
final class VisitorKind
{
    /** How long a browser may sit still before "nothing happened" says something. */
    public const QUIET_SECONDS = 20;

    /** @return 'person'|'bot'|null */
    public static function of(?ChatSession $session, bool $wrote = false): ?string
    {
        if ($wrote || ($session !== null && LiveChat::humanReady() && $session->getAttribute('interacted_at') !== null)) {
            return 'person';
        }

        if ($session === null || ! LiveChat::humanReady()) {
            return null;
        }

        $since = $session->visit_started_at ?? $session->first_seen_at;
        $quiet = $since !== null && $since->lt(now()->subSeconds(self::QUIET_SECONDS));
        $vpn = VisitorLocation::vpn($session->is_proxy, $session->isp, $session->country, $session->getAttribute('browser_tz'))['vpn'];

        return $quiet && $vpn ? 'bot' : null;
    }
}
