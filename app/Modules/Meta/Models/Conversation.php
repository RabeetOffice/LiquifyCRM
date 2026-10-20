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
 * One chat thread with a person: Messenger (to a Facebook Page) or an
 * Instagram direct message (to the account linked to that Page); WhatsApp
 * and email later. The contact is the id Meta gives the person for that Page
 * or account (PSID or IGSID). The last inbound time drives the 24-hour reply
 * window both channels share.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $lead_id
 * @property string $channel
 * @property string $contact_external_id
 * @property string|null $contact_name
 * @property string|null $contact_handle
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

    /** A chat's changes (unread count, last message, the contact's name) reach only the screens that show chats (Live::touchChat). */
    public const LIVE_CHAT = true;

    public const CHANNEL_MESSENGER = 'messenger';

    public const CHANNEL_INSTAGRAM = 'instagram';

    /** Messenger and Instagram let a business reply freely for 24 hours after the person's last message. */
    public const REPLY_WINDOW_HOURS = 24;

    /** Instagram takes a text of up to 1,000 bytes (an emoji counts as four); Messenger 2,000 characters. */
    public const INSTAGRAM_MAX_BYTES = 1000;

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
     * The person's Messenger or Instagram picture, when Meta shared one. Read
     * from the raw attributes so a server that has not run "Update system" yet
     * (no such column) simply shows the initials.
     */
    public function pictureUrl(): ?string
    {
        $picture = $this->getAttributes()['contact_picture'] ?? null;

        return is_string($picture) && $picture !== '' ? $picture : null;
    }

    public function isInstagram(): bool
    {
        return $this->channel === self::CHANNEL_INSTAGRAM;
    }

    /** "Messenger" or "Instagram", for the screens. */
    public function channelLabel(): string
    {
        return $this->isInstagram() ? 'Instagram' : 'Messenger';
    }

    /** Who the person wrote to: the Facebook Page, or the Instagram account linked to it. */
    public function accountName(): string
    {
        if ($this->source === null) {
            return $this->isInstagram() ? 'Account removed' : 'Page removed';
        }

        return $this->isInstagram() ? ($this->source->instagramHandle() ?? $this->source->name) : $this->source->name;
    }

    /** The person's Instagram @username, when Instagram shared it (Messenger has none). Raw, for the same reason as the picture. */
    public function handle(): ?string
    {
        $handle = $this->getAttributes()['contact_handle'] ?? null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /** The person's public Instagram profile. Instagram shares the @username; Messenger shares no profile link at all. */
    public function profileUrl(): ?string
    {
        $handle = $this->handle();

        return $this->isInstagram() && $handle !== null && preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) === 1
            ? 'https://www.instagram.com/'.$handle.'/'
            : null;
    }

    /** Why a text is too long for this channel, or null when it fits. */
    public function replyTooLong(string $text): ?string
    {
        if ($this->isInstagram() && strlen($text) > self::INSTAGRAM_MAX_BYTES) {
            return 'Instagram takes up to 1,000 characters in one message (an emoji counts as four). Shorten it or send it in two.';
        }

        return mb_strlen($text) > 2000 ? 'Messenger takes up to 2,000 characters in one message. Shorten it or send it in two.' : null;
    }

    /** The Page's own inbox at Meta (Messenger and Instagram together), where the person's profile can be seen. Meta gives no link to a Messenger profile. */
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
        if (filled($this->contact_name)) {
            return (string) $this->contact_name;
        }

        $handle = $this->handle();

        return $handle !== null ? '@'.$handle : $this->channelLabel().' contact';
    }
}
