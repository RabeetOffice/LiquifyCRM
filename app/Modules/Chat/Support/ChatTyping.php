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
    private const SECONDS = 5;

    private const TELL_EVERY = 3;

    public static function visitorTypes(int $brandId, int $conversationId): void
    {
        Cache::put('chat:typing:visitor:'.$conversationId, true, self::SECONDS);

        // The team's open chat is told now and then while the visitor keeps typing; its dots hide by themselves soon
        // after the last word (conversation-thread), so they never outlive the typing.
        if (Cache::add('chat:typing:told:'.$conversationId, true, self::TELL_EVERY)) {
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

    /** The visitor emptied their box: the team's dots go at once. */
    public static function visitorStops(int $brandId, int $conversationId): void
    {
        if (Cache::pull('chat:typing:visitor:'.$conversationId) !== null) {
            Cache::forget('chat:typing:told:'.$conversationId);
            Live::touchChat($brandId);
        }
    }

    /** The team member emptied their box: the visitor's dots go at once. */
    public static function agentStops(int $conversationId): void
    {
        if (Cache::pull('chat:typing:agent:'.$conversationId) !== null) {
            Realtime::notifyVisitor($conversationId);
        }
    }

    /** A message was sent: the dots stop. */
    public static function stop(int $conversationId, bool $visitor): void
    {
        Cache::forget('chat:typing:'.($visitor ? 'visitor' : 'agent').':'.$conversationId);
    }
}
