<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\ChatNotifier;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorChats;
use App\Modules\Chat\Support\VisitorSpam;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ChatContacts;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A visitor writes in the chat. The conversation is started with the first message; it has no lead. When the
 * visitor writes an email address or a phone number and has not given their details yet, the widget is told
 * ("suggest") so it can ask whether that is the best way to reach them: only their yes makes a lead. A chat
 * the team closed opens again, and the team is told: the first message of a chat rings for everybody who may
 * answer it, a later one only for the person who has it.
 */
final class SendVisitorMessage
{
    public function __construct(private readonly ReplyToVisitor $lines, private readonly ChatNotifier $notifier) {}

    /**
     * @return array{message: Message, conversation: Conversation, suggest: array{email?: string, phone?: string}|null}
     *
     * @throws RuntimeException when the message is refused; its text is for the visitor
     */
    public function handle(ChatWidget $widget, ChatVisitor $visitor, string $body, bool $detect = true): array
    {
        $body = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', str_replace(["\r\n", "\r"], "\n", $body)));

        if ($body === '') {
            throw new RuntimeException('Write a message first.');
        }

        if (mb_strlen($body) > VisitorSpam::MAX_MESSAGE) {
            throw new RuntimeException('That message is too long. Please send it in parts.');
        }

        if (VisitorSpam::tooManyLinks($body)) {
            throw new RuntimeException('Please send one link at a time.');
        }

        $started = false;

        [$conversation, $message] = DB::transaction(function () use ($widget, $visitor, $body, &$started): array {
            $conversation = VisitorChats::conversationFor($visitor, $widget, true) ?? throw new RuntimeException('The chat could not start.');

            // The first thing this visitor wrote, or the first after the team closed the chat: it is a chat for the team to pick up.
            $started = $conversation->isClosed()
                || ! Message::query()->where('conversation_id', $conversation->getKey())->where('direction', Message::IN)->exists();

            $message = Message::query()->create([
                'conversation_id' => $conversation->getKey(),
                'direction' => Message::IN,
                'kind' => 'text',
                'sender_type' => 'visitor',
                'body' => $body,
            ]);

            $conversation->forceFill([
                'last_inbound_at' => now(),
                'last_message_at' => now(),
                'last_message_preview' => Str::limit((string) Str::of($body)->squish(), 190, ''),
                'unread_count' => $conversation->unread_count + 1,
            ])->save();

            if ($conversation->isClosed() && LiveChat::workReady()) {
                $conversation->forceFill(['chat_status' => 'open', 'chat_closed_at' => null])->save();
                $this->lines->event($conversation, 'Reopened: the visitor wrote again');
            }

            return [$conversation, $message];
        });

        ChatTyping::stop((int) $conversation->getKey(), visitor: true);

        // They wrote again: an archived lead comes back to the lists, and a lead made from the pre-chat form
        // (which had no question) gets their first words as its message.
        if ($conversation->lead_id !== null) {
            $lead = Lead::query()->find($conversation->lead_id);

            if ($lead !== null && blank($lead->message)) {
                $lead->forceFill(['message' => $body])->save();
            }

            $lead?->heardFrom();
        }

        // A bell that fails to ring must not lose the visitor's message.
        try {
            $this->notifier->visitorWrote($conversation, $body, $started);
        } catch (Throwable $exception) {
            report($exception);
        }

        return ['message' => $message, 'conversation' => $conversation, 'suggest' => $detect && $visitor->lead_id === null ? $this->contactIn($visitor, $body) : null];
    }

    /** @return array{email?: string, phone?: string}|null */
    private function contactIn(ChatVisitor $visitor, string $body): ?array
    {
        $brand = Brand::query()->find($visitor->brand_id);

        if ($brand === null) {
            return null;
        }

        $found = array_filter(ChatContacts::in($body, $brand), fn (?string $value): bool => $value !== null);

        return $found === [] ? null : $found;
    }
}
