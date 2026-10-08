<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Messenger "delivered" and "seen" receipts: every reply sent up to the
 * watermark moves to that state. Not kept in the event log (they carry no lead).
 */
final class UpdateMessageReceipts implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $pageId,
        public readonly string $psid,
        public readonly string $state,
        public readonly int $watermarkMs,
    ) {}

    public function handle(): void
    {
        $status = MessageStatus::tryFrom($this->state);
        $pageKey = MetaSource::query()->where('external_id', $this->pageId)->value('id');

        if ($pageKey === null || ! in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true)) {
            return;
        }

        $conversationId = Conversation::query()->acrossBrands()
            ->where('meta_source_id', $pageKey)
            ->where('contact_external_id', $this->psid)
            ->value('id');

        if ($conversationId === null) {
            return;
        }

        Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', Message::OUT)
            ->whereIn('status', $status === MessageStatus::Read ? [MessageStatus::Sent->value, MessageStatus::Delivered->value] : [MessageStatus::Sent->value])
            ->where('created_at', '<=', CarbonImmutable::createFromTimestampMs($this->watermarkMs)->addSeconds(5))
            ->update(['status' => $status->value]);
    }
}
