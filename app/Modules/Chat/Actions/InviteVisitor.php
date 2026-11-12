<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Chat\Support\VisitorChats;
use App\Modules\Chat\Support\VisitorToken;
use App\Modules\Core\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The team writes first to somebody who is on the website. The words become the first message of a chat, from the
 * person who invited, and the visitor's browser shows them as a card by the chat button on its next ping; opening the
 * chat takes the visitor into that conversation. The visitor is not a lead and becomes one only if they answer with
 * an email address or a phone number, like everybody.
 */
final class InviteVisitor
{
    /** One invitation to a browser in this many minutes: a person is asked, not pestered. */
    private const WAIT_MINUTES = 10;

    public function __construct(private readonly ReplyToVisitor $replies) {}

    /** @throws RuntimeException when the invitation is refused; its words are for the person inviting */
    public function handle(ChatSession $session, User $by, string $text): void
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if ($text === '') {
            throw new RuntimeException('Write what you want to say first.');
        }

        if (mb_strlen($text) > 300) {
            throw new RuntimeException('Keep the invitation short: up to 300 characters.');
        }

        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subSeconds(ChatSession::ONLINE_SECONDS))) {
            throw new RuntimeException('This visitor has just left the website.');
        }

        if ($session->invite_sent_at !== null && $session->invite_sent_at->gt(now()->subMinutes(self::WAIT_MINUTES))) {
            throw new RuntimeException('This visitor was invited a moment ago. Wait a few minutes.');
        }

        $widget = $session->widget ?? ChatWidget::query()->where('is_active', true)->first() ?? throw new RuntimeException('This website has no live chat switched on.');

        DB::transaction(function () use ($session, $by, $text, $widget): void {
            $visitor = $session->visitor ?? ChatVisitor::query()->create([
                'brand_id' => $session->brand_id,
                'chat_widget_id' => $widget->getKey(),
                'visitor_token' => VisitorToken::issue()['token'],
                'country' => $session->country,
                ...(LiveChat::geoReady() ? ['ip' => $session->ip, 'city' => $session->city, 'region' => $session->region, 'isp' => $session->isp, 'timezone' => $session->timezone, 'is_proxy' => $session->is_proxy] : []),
                'device' => $session->device,
                'browser' => $session->browser,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            $conversation = VisitorChats::conversationFor($visitor, $widget, true);

            if ($conversation === null) {
                throw new RuntimeException('The chat could not start.');
            }

            $message = $this->replies->reply($conversation, $by, $text);
            $this->replies->event($conversation, $by->name.' invited this visitor from '.$session->path());

            $session->forceFill([
                'chat_visitor_id' => $visitor->getKey(),
                'invite_text' => Str::limit($text, 300, ''),
                'invite_from' => explode(' ', trim($by->name))[0],
                'invite_sent_at' => now(),
                'invite_shown_at' => null,
            ])->save();

            Audit::log('chat.invited', $conversation, ['page' => $session->path(), 'message_id' => $message->getKey()], 'Visitor invited to chat from '.$session->path(), brand: $session->brand_id, causer: $by, logName: 'chat');
        });
    }
}
