<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Support\ChatFiles;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Chat\Support\Realtime;
use App\Modules\Chat\Support\VisitorSpam;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The team answers a visitor in a website chat (with words, a file, or both), or leaves a note for the team that
 * the visitor never sees. There is no 24-hour window on a website: the visitor reads it on their next poll. The
 * first answer of a chat is timed (for the reports), and a chat nobody looks after goes to whoever answers it first.
 */
final class ReplyToVisitor
{
    /** @throws RuntimeException when the text or the file is refused; its words are for the person typing */
    public function reply(Conversation $conversation, User $by, string $text, ?UploadedFile $file = null): Message
    {
        $body = $file !== null && trim($text) === '' ? '' : $this->clean($text);

        if ($file !== null && ($why = ChatFiles::refusal($file, ChatFiles::maxBytes($conversation->widget))) !== null) {
            throw new RuntimeException($why);
        }

        if ($conversation->isClosed()) {
            throw new RuntimeException('This chat is closed. Reopen it to reply.');
        }

        $message = DB::transaction(function () use ($conversation, $by, $body, $file): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->getKey(),
                'direction' => Message::OUT,
                'kind' => $file !== null ? 'file' : 'text',
                'sender_type' => 'agent',
                'sent_by' => $by->getKey(),
                'body' => $body !== '' ? $body : null,
            ]);

            $attachment = $file !== null ? ChatFiles::keep($file, $message, $conversation) : null;

            $conversation->forceFill([
                'last_message_at' => now(),
                'last_message_preview' => $attachment !== null && $body === ''
                    ? 'File: '.$attachment->original_name
                    : Str::limit((string) Str::of($body)->squish(), 190, ''),
                'unread_count' => 0,
                'first_response_at' => $conversation->first_response_at ?? now(),
            ])->save();

            // A visitor's chat nobody looks after is the first person's who answers it.
            if ($conversation->lead_id === null && $conversation->chat_assigned_to === null) {
                $conversation->forceFill(['chat_assigned_to' => $by->getKey()])->save();
                $this->event($conversation, $by->name.' took this chat');
            }

            ChatTyping::stop((int) $conversation->getKey(), visitor: false);

            return $message;
        });

        // The visitor's chat box hears about the answer at once.
        Realtime::notifyVisitor($conversation);

        return $message;
    }

    /** @throws RuntimeException */
    public function note(Conversation $conversation, User $by, string $text): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->getKey(),
            'direction' => Message::OUT,
            'kind' => 'note',
            'sender_type' => 'agent',
            'sent_by' => $by->getKey(),
            'body' => $this->clean($text),
        ]);
    }

    /** A line in the chat that says what happened ("Closed by Sami"); only the team sees it. */
    public function event(Conversation $conversation, string $text): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->getKey(),
            'direction' => Message::OUT,
            'kind' => 'event',
            'sender_type' => 'system',
            'body' => $text,
        ]);
    }

    private function clean(string $text): string
    {
        $body = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', str_replace(["\r\n", "\r"], "\n", $text)));

        if ($body === '') {
            throw new RuntimeException('Write something first.');
        }

        if (mb_strlen($body) > VisitorSpam::MAX_MESSAGE) {
            throw new RuntimeException('That is too long for one message. Send it in parts.');
        }

        return $body;
    }
}
