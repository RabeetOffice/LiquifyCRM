<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Support\MetaGraph;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Delivers one Messenger or Instagram reply and logs "reply sent" or "reply failed". */
final class SendMessengerReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    public function handle(MetaGraph $graph): void
    {
        $message = Message::query()->with(['conversation.source', 'sender'])->find($this->messageId);

        if ($message === null || $message->status !== MessageStatus::Sending) {
            return;
        }

        $conversation = $message->conversation;
        $channel = $conversation->channelLabel();
        $lead = $conversation->lead_id !== null ? Lead::query()->acrossBrands()->find($conversation->lead_id) : null;

        try {
            $page = $conversation->source ?? throw new MetaException('The Facebook Page of this chat is no longer connected.');

            if (! $conversation->canReply()) {
                throw new MetaException('The 24-hour reply window has closed.');
            }

            $externalId = $graph->sendMessage($page, $conversation->contact_external_id, (string) $message->body, instagram: $conversation->isInstagram());

            $message->forceFill(['status' => MessageStatus::Sent, 'external_id' => $externalId !== '' ? $externalId : null, 'error' => null])->save();

            if ($lead !== null && $lead->first_contacted_at === null) {
                $lead->forceFill(['first_contacted_at' => now()])->saveQuietly();
            }

            $lead?->touchActivity();

            Audit::log('messages.reply_sent', $lead ?? $conversation, ['conversation_id' => $conversation->getKey(), 'excerpt' => mb_substr((string) $message->body, 0, 140)], $channel.' reply sent', brand: $conversation->brand_id, causer: $message->sender, logName: 'messages');
        } catch (Throwable $exception) {
            $reason = $exception instanceof MetaException ? $exception->forPeople() : $exception->getMessage();
            $message->forceFill(['status' => MessageStatus::Failed, 'error' => mb_substr($reason, 0, 500)])->save();

            Audit::log('messages.reply_failed', $lead ?? $conversation, ['conversation_id' => $conversation->getKey(), 'error' => $message->error, 'code' => $exception->getCode(), 'subcode' => $exception instanceof MetaException ? $exception->subcode : null], $channel.' reply failed', brand: $conversation->brand_id, causer: $message->sender, logName: 'messages');

            $message->sender?->notify(new AppNotification(
                'Your '.$channel.' reply was not sent',
                (string) $message->error,
                'danger',
                url('/b/'.$this->brandSlug($conversation->brand_id).'/inbox?chat='.$conversation->getKey()),
                icon: 'heroicon-o-chat-bubble-left-right',
                actionLabel: 'Open chat',
            ));

            if (! $exception instanceof MetaException) {
                report($exception);
            }
        }
    }

    private function brandSlug(int $brandId): string
    {
        return (string) Brand::query()->whereKey($brandId)->value('slug');
    }
}
