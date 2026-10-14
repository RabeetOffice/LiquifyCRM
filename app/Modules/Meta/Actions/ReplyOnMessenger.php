<?php

declare(strict_types=1);

namespace App\Modules\Meta\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Jobs\SendMessengerReply;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;

/**
 * A reply typed in the Inbox or on the lead page, on Messenger or Instagram
 * (the chat's channel). It is sent inside the request, so the screen answers
 * with "Sent" or the reason it was not. Both channels only allow it within
 * 24 hours of the person's last message.
 */
final class ReplyOnMessenger
{
    public function handle(Conversation $conversation, string $text, User $by): Message
    {
        $text = trim($text);

        if ($text === '') {
            throw new MetaException('Write a message first.');
        }

        if (($tooLong = $conversation->replyTooLong($text)) !== null) {
            throw new MetaException($tooLong);
        }

        if (! $conversation->canReply()) {
            throw new MetaException('The 24-hour reply window has closed. You can reply again after this person writes.');
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->getKey(),
            'direction' => Message::OUT,
            'body' => mb_substr($text, 0, 2000),
            'status' => MessageStatus::Sending,
            'sent_by' => $by->getKey(),
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => mb_substr('You: '.$text, 0, 190),
            'unread_count' => 0,
        ])->save();

        // Sent inside this request: when the screen answers, the message is already "Sent" (or says why not),
        // instead of showing "Sending" until someone looks again.
        SendMessengerReply::dispatchSync((int) $message->getKey());

        return $message->refresh();
    }
}
