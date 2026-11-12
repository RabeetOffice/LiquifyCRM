<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorChats;
use App\Modules\Meta\Models\Message;
use RuntimeException;

/**
 * The visitor rates the chat with a thumb up or down (user request, 12 Nov 2026). Only a chat the team has answered can
 * be rated; the visitor may change their mind, and each change is a line in the chat for the team (never sent to the
 * visitor, it is an event). Who is rated is who answered: the reports read the rating with the chat's people.
 */
final class RateChat
{
    public const RATINGS = ['up' => 'good', 'down' => 'bad'];

    public function __construct(private readonly ReplyToVisitor $replies) {}

    /** @throws RuntimeException when there is nothing to rate */
    public function handle(ChatWidget $widget, ChatVisitor $visitor, string $rating): void
    {
        if (! LiveChat::ratingReady() || ! array_key_exists($rating, self::RATINGS) || $widget->look('rating') === '0') {
            throw new RuntimeException('Rating is not available.');
        }

        $conversation = VisitorChats::conversationFor($visitor, $widget, false);

        $answered = $conversation !== null && Message::query()->where('conversation_id', $conversation->getKey())
            ->where('direction', Message::OUT)->where('kind', 'text')->exists();

        if (! $answered) {
            throw new RuntimeException('There is nothing to rate yet.');
        }

        if ($conversation->getAttribute('rating') === $rating) {
            return;
        }

        $conversation->forceFill(['rating' => $rating, 'rated_at' => now()])->save();
        $this->replies->event($conversation, 'The visitor rated this chat '.self::RATINGS[$rating].($rating === 'up' ? ' 👍' : ' 👎'));
    }
}
