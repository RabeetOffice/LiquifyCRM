<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Chat\Models\ChatCall;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Chat\Support\ChatNotifier;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorChats;
use RuntimeException;

/**
 * A website visitor pressed the phone button in the chat box (user request, 12 Nov 2026; docs/CALLS_PLAN.md): the call
 * rings in the CRM for everybody who works the brand's website visitors. Only a visitor starts a call; the team can
 * invite them to (ReplyToVisitor::inviteToCall).
 */
final class StartCall
{
    public function __construct(private readonly EndCall $end, private readonly ReplyToVisitor $replies, private readonly ChatNotifier $notifier) {}

    /** Whether this chat box offers calls at all. */
    public static function offered(ChatWidget $widget): bool
    {
        return LiveChat::callsReady() && $widget->look('call') !== '0';
    }

    /** A browser's call description: plain text that starts as every one does and has sound in it, nothing else. */
    public static function looksLikeSdp(mixed $sdp): bool
    {
        return is_string($sdp) && strlen($sdp) <= 20000 && str_starts_with($sdp, 'v=0') && str_contains($sdp, 'm=audio');
    }

    /**
     * The description as a browser expects it: every line ended, the last one too (the framework trims what a
     * request sends, and a browser refuses a description whose last line is not ended).
     */
    public static function sdp(string $sdp): string
    {
        return rtrim($sdp)."\r\n";
    }

    /** @throws RuntimeException with words for the visitor */
    public function handle(ChatWidget $widget, ChatVisitor $visitor, string $offer): ChatCall
    {
        if (! self::offered($widget)) {
            throw new RuntimeException('Calls are not available here.');
        }

        if (! self::looksLikeSdp($offer)) {
            throw new RuntimeException('The call could not start. Please try again.');
        }

        if (! AgentPresence::online((int) $widget->brand_id)) {
            throw new RuntimeException('Nobody is here to take a call right now. Please leave us a message.');
        }

        $conversation = VisitorChats::conversationFor($visitor, $widget, true);

        // One call at a time in a chat; one left over from a closed tab is settled first.
        $going = ChatCall::query()->where('conversation_id', $conversation->getKey())->whereIn('status', ChatCall::LIVE)->get();

        foreach ($going as $old) {
            if ($this->end->settle($old)->isLive()) {
                throw new RuntimeException('A call is already going on in this chat.');
            }
        }

        $call = ChatCall::query()->create([
            'brand_id' => $conversation->brand_id,
            'conversation_id' => $conversation->getKey(),
            'status' => ChatCall::RINGING,
            'offer' => self::sdp($offer),
            'rang_at' => now(),
            'visitor_seen_at' => now(),
        ]);

        // A closed chat is open again: the visitor is here and wants to talk.
        if ($conversation->isClosed()) {
            $conversation->forceFill(['chat_status' => 'open', 'chat_closed_at' => null])->save();
        }

        $this->replies->event($conversation, 'The visitor is calling');
        $this->notifier->calling($conversation);

        return $call;
    }
}
