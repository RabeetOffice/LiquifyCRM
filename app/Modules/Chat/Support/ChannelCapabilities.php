<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Meta\Models\Conversation;

/**
 * What each channel of the Inbox can do, in one place, so a screen asks "can this chat do X?" instead of
 * checking for Messenger or Instagram. The website's live chat has more than the two Meta channels (files,
 * typing, the visitor's page trail), and the Meta channels have a 24-hour reply window the website has not.
 */
final class ChannelCapabilities
{
    public const REPLY_WINDOW = 'reply_window';

    public const FILES = 'files';

    public const TYPING = 'typing';

    public const VISITOR_CARD = 'visitor_card';

    public const ONLINE_DOT = 'online_dot';

    public const AI_HANDLING = 'ai_handling';

    public const CARDS = 'cards';

    public const RATING = 'rating';

    /** @var array<string, list<string>> */
    private const MAP = [
        Conversation::CHANNEL_MESSENGER => [self::REPLY_WINDOW],
        Conversation::CHANNEL_INSTAGRAM => [self::REPLY_WINDOW],
        Conversation::CHANNEL_LIVECHAT => [self::FILES, self::TYPING, self::VISITOR_CARD, self::ONLINE_DOT, self::AI_HANDLING, self::CARDS, self::RATING],
    ];

    public static function has(string $channel, string $capability): bool
    {
        return in_array($capability, self::MAP[$channel] ?? [], true);
    }

    /** @return list<string> */
    public static function for(string $channel): array
    {
        return self::MAP[$channel] ?? [];
    }
}
