<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Chat\Models\ChatAttachment;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;

/**
 * The visitor's side of a chat: which conversation is theirs, and the messages as the widget shows them. A
 * visitor sees what they and the team wrote, never the team's internal notes or event lines.
 */
final class VisitorChats
{
    /** The visitor's chat, started here when they write or give their details for the first time. */
    public static function conversationFor(ChatVisitor $visitor, ChatWidget $widget, bool $create): ?Conversation
    {
        $conversation = Conversation::query()
            ->where('channel', Conversation::CHANNEL_LIVECHAT)
            ->where('chat_visitor_id', $visitor->getKey())
            ->latest('id')
            ->first();

        if ($conversation !== null || ! $create) {
            return $conversation;
        }

        return Conversation::query()->create([
            'brand_id' => $visitor->brand_id,
            'lead_id' => $visitor->lead_id,
            'channel' => Conversation::CHANNEL_LIVECHAT,
            'contact_external_id' => $visitor->visitor_token,
            'contact_name' => $visitor->name,
            'chat_widget_id' => $widget->getKey(),
            'chat_visitor_id' => $visitor->getKey(),
        ]);
    }

    /**
     * @return list<array{id: int, from: string, body: string, at: string, name: ?string, files: list<array{name: string, size: string, image: bool, url: string}>}>
     */
    public static function messages(Conversation $conversation, int $after = 0): array
    {
        return Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('kind', ['text', 'file'])
            ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
            ->with(['sender', ...(LiveChat::extrasReady() ? ['chatFiles'] : [])])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $message): array => self::row($message))
            ->all();
    }

    /** @return array{id: int, from: string, body: string, at: string, name: ?string, files: list<array{name: string, size: string, image: bool, url: string}>} */
    public static function row(Message $message): array
    {
        $from = match (true) {
            ! $message->isOutbound() => 'visitor',
            $message->sender_type === 'ai' => 'ai',
            default => 'team',
        };

        return [
            'id' => (int) $message->getKey(),
            'from' => $from,
            'body' => (string) $message->body,
            'at' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'name' => $from === 'team' && $message->sender !== null ? self::firstName($message->sender->name) : null,
            'files' => $message->relationLoaded('chatFiles')
                ? $message->chatFiles->map(fn (ChatAttachment $file): array => ['name' => $file->original_name, 'size' => $file->humanSize(), 'image' => $file->isImage(), 'url' => ChatProxy::url(request(), $file->visitorUrl())])->values()->all()
                : [],
            // The team asked for the visitor's details: the chat box shows its form under the message.
            'ask' => $from === 'team' && is_array($message->raw) && ($message->raw['ask'] ?? null) === 'details' ? 'details' : null,
        ];
    }

    private static function firstName(string $name): string
    {
        return explode(' ', trim($name))[0];
    }
}
