<?php

declare(strict_types=1);

namespace App\Modules\Meta\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread with a person (Messenger now; WhatsApp and email later).
 * The last inbound time drives Messenger's 24-hour reply window.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $lead_id
 * @property string $channel
 * @property string $contact_external_id
 * @property string|null $contact_name
 * @property string|null $contact_picture
 * @property CarbonImmutable|null $contact_synced_at
 * @property int|null $meta_source_id
 * @property CarbonImmutable|null $last_inbound_at
 * @property CarbonImmutable|null $last_message_at
 * @property string|null $last_message_preview
 * @property int $unread_count
 * @property-read Lead|null $lead
 * @property-read MetaSource|null $source
 */
class Conversation extends Model
{
    use BelongsToBrand;

    public const CHANNEL_MESSENGER = 'messenger';

    /** Messenger lets a Page reply freely for 24 hours after the person's last message. */
    public const REPLY_WINDOW_HOURS = 24;

    protected $fillable = [
        'brand_id', 'lead_id', 'channel', 'contact_external_id', 'contact_name', 'meta_source_id',
        'last_inbound_at', 'last_message_at', 'last_message_preview', 'unread_count',
    ];

    protected $attributes = [
        'channel' => self::CHANNEL_MESSENGER, 'lead_id' => null, 'contact_name' => null, 'last_inbound_at' => null,
        'last_message_at' => null, 'last_message_preview' => null, 'unread_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'contact_synced_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /**
     * The person's Messenger picture, when Facebook shared one. Read from the
     * raw attributes so a server that has not run "Update system" yet (no
     * such column) simply shows the initials.
     */
    public function pictureUrl(): ?string
    {
        $picture = $this->getAttributes()['contact_picture'] ?? null;

        return is_string($picture) && $picture !== '' ? $picture : null;
    }

    /** The Page's own inbox at Facebook, where the person's profile can be seen. Meta gives no link to a profile. */
    public function facebookInboxUrl(): ?string
    {
        $pageId = $this->source?->external_id;

        return $pageId !== null ? 'https://business.facebook.com/latest/inbox/all?asset_id='.rawurlencode((string) $pageId) : null;
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<MetaSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MetaSource::class, 'meta_source_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * Threads follow their lead: a person sees a chat only when they may see the lead.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('lead', fn (Builder $lead): Builder => $lead->visibleTo($user));
    }

    public function replyWindowEndsAt(): ?CarbonImmutable
    {
        return $this->last_inbound_at?->addHours(self::REPLY_WINDOW_HOURS);
    }

    public function canReply(): bool
    {
        $ends = $this->replyWindowEndsAt();

        return $ends !== null && $ends->isFuture();
    }

    public function displayName(): string
    {
        return $this->contact_name ?: 'Messenger contact';
    }
}
