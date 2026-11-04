<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Support\ChatAccess;
use App\Modules\Chat\Support\ChatNotifier;
use App\Modules\Chat\Support\Realtime;
use App\Modules\Core\Support\Audit;
use App\Modules\Meta\Models\Conversation;
use RuntimeException;

/**
 * Closing, reopening and giving a website chat to somebody else. Each leaves a line in the chat that says who
 * did it (the visitor never sees those lines) and a line in the logs.
 */
final class ChangeChat
{
    public function __construct(private readonly ReplyToVisitor $lines) {}

    public function close(Conversation $conversation, User $by): void
    {
        if ($conversation->isClosed()) {
            return;
        }

        $conversation->forceFill(['chat_status' => 'closed', 'chat_closed_at' => now(), 'unread_count' => 0])->save();
        $this->lines->event($conversation, 'Closed by '.$by->name);
        Audit::log('chat.closed', $conversation, [], 'Live chat closed: '.$conversation->displayName(), brand: $conversation->brand_id, causer: $by, logName: 'chat');
        Realtime::notifyVisitor($conversation);
    }

    public function reopen(Conversation $conversation, User $by, ?string $why = null): void
    {
        if (! $conversation->isClosed()) {
            return;
        }

        $conversation->forceFill(['chat_status' => 'open', 'chat_closed_at' => null])->save();
        $this->lines->event($conversation, $why ?? 'Reopened by '.$by->name);
        Audit::log('chat.reopened', $conversation, [], 'Live chat reopened: '.$conversation->displayName(), brand: $conversation->brand_id, causer: $by, logName: 'chat');
        Realtime::notifyVisitor($conversation);
    }

    /**
     * Give a visitor's chat (one without a lead) to a person of the brand, or back to the team with null.
     *
     * @throws RuntimeException when that person cannot be given this chat
     */
    public function assign(Conversation $conversation, ?User $to, User $by): void
    {
        if ($conversation->lead_id !== null) {
            throw new RuntimeException('This chat belongs to a lead: assign the lead.');
        }

        if ($to !== null && ! ChatAccess::canSeeVisitors($to, (int) $conversation->brand_id)) {
            throw new RuntimeException($to->name.' cannot be given this chat.');
        }

        if ($conversation->chat_assigned_to === $to?->getKey()) {
            return;
        }

        $conversation->forceFill(['chat_assigned_to' => $to?->getKey()])->save();
        $this->lines->event($conversation, $to !== null ? ($by->is($to) ? $by->name.' took this chat' : $by->name.' gave this chat to '.$to->name) : $by->name.' gave this chat back to the team');
        Audit::log('chat.assigned', $conversation, ['to' => $to?->getKey()], 'Live chat '.($to !== null ? 'given to '.$to->name : 'given back to the team').': '.$conversation->displayName(), brand: $conversation->brand_id, causer: $by, logName: 'chat');

        if ($to !== null) {
            app(ChatNotifier::class)->assigned($conversation, $to, $by);
        }
    }
}
