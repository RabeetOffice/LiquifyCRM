<?php

declare(strict_types=1);

namespace App\Modules\Meta\Jobs;

use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\Instagram;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Messenger "delivered" and "seen" receipts, and Instagram's "seen": every
 * reply sent up to the watermark (Instagram: the moment it was seen) moves to
 * that state. Not kept in the event log (they carry no lead).
 */
final class UpdateMessageReceipts implements ShouldQueue
{
    use Queueable;

    /** On Instagram the first id is the Instagram account linked to the Page, the second the person's IGSID. */
    public function __construct(
        public readonly string $pageId,
        public readonly string $psid,
        public readonly string $state,
        public readonly int $watermarkMs,
        public readonly bool $instagram = false,
    ) {}

    public function handle(): void
    {
        $status = MessageStatus::tryFrom($this->state);

        if ($this->instagram && ! Instagram::ready()) {
            return;
        }

        $pageKey = MetaSource::query()->where($this->instagram ? 'instagram_id' : 'external_id', $this->pageId)->value('id');

        if ($pageKey === null || ! in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true)) {
            return;
        }

        $conversationId = Conversation::query()->acrossBrands()
            ->where('meta_source_id', $pageKey)
            ->where('channel', $this->instagram ? Conversation::CHANNEL_INSTAGRAM : Conversation::CHANNEL_MESSENGER)
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
