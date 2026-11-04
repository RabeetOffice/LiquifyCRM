<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Core\Support\Live;
use Illuminate\Support\Facades\Cache;

/**
 * "Typing…" in both directions, kept a few seconds in the cache: the side that types says so now and then, and
 * the other side shows the dots while the note is fresh. The agent's open chat is told through the brand's chat
 * stamp (at most once every few seconds, so a long message does not flood every open Inbox).
 */
final class ChatTyping
{
    private const SECONDS = 6;

    private const TELL_EVERY = 4;

    public static function visitorTypes(int $brandId, int $conversationId): void
    {
        $key = 'chat:typing:visitor:'.$conversationId;
        $fresh = Cache::has($key);

        Cache::put($key, true, self::SECONDS);

        if (! $fresh && Cache::add('chat:typing:told:'.$conversationId, true, self::TELL_EVERY)) {
            Live::touchChat($brandId);
        }
    }

    public static function agentTypes(int $conversationId): void
    {
        $key = 'chat:typing:agent:'.$conversationId;
        $fresh = ! Cache::has($key);

        Cache::put($key, true, self::SECONDS);

        // The visitor's chat box shows the dots at once, instead of at its next ask.
        if ($fresh) {
            Realtime::notifyVisitor($conversationId);
        }
    }

    public static function visitorIsTyping(int $conversationId): bool
    {
        return Cache::has('chat:typing:visitor:'.$conversationId);
    }

    public static function agentIsTyping(int $conversationId): bool
    {
        return Cache::has('chat:typing:agent:'.$conversationId);
    }

    /** A message was sent: the dots stop. */
    public static function stop(int $conversationId, bool $visitor): void
    {
        Cache::forget('chat:typing:'.($visitor ? 'visitor' : 'agent').':'.$conversationId);
    }
}
