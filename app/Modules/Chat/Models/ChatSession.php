<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A browser on a brand's website, as the chat box's launcher reports it every half minute: which page it is on, how it
 * got there, how many times it came. Not a person: it becomes somebody to the team once it chats (`chat_visitor_id`).
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $chat_widget_id
 * @property string $vid
 * @property int|null $chat_visitor_id
 * @property string|null $current_url
 * @property string|null $current_title
 * @property string|null $referrer
 * @property string|null $country
 * @property string|null $device
 * @property string|null $browser
 * @property int $visits_count
 * @property CarbonImmutable|null $visit_started_at
 * @property CarbonImmutable|null $page_changed_at
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property string|null $invite_text
 * @property string|null $invite_from
 * @property CarbonImmutable|null $invite_sent_at
 * @property CarbonImmutable|null $invite_shown_at
 * @property-read ChatVisitor|null $visitor
 */
class ChatSession extends Model
{
    use BelongsToBrand;

    /** A browser that has not reported for this long has left. The launcher reports every 30 seconds. */
    public const ONLINE_SECONDS = 90;

    /** After this long without a report, coming back counts as a new visit. */
    public const NEW_VISIT_MINUTES = 30;

    protected $fillable = [
        'brand_id', 'chat_widget_id', 'vid', 'chat_visitor_id', 'current_url', 'current_title', 'referrer', 'country', 'device', 'browser',
        'visits_count', 'visit_started_at', 'page_changed_at', 'first_seen_at', 'last_seen_at', 'invite_text', 'invite_from', 'invite_sent_at', 'invite_shown_at',
    ];

    protected function casts(): array
    {
        return [
            'visits_count' => 'integer',
            'visit_started_at' => 'datetime',
            'page_changed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'invite_sent_at' => 'datetime',
            'invite_shown_at' => 'datetime',
        ];
    }

    /**
     * Browsers on the website right now.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_SECONDS));
    }

    /** What the team calls them: the name they gave in the chat, else "Visitor 1234". */
    public function displayName(): string
    {
        return $this->visitor !== null ? $this->visitor->displayName() : 'Visitor '.$this->getKey();
    }

    /** The two-letter country as a flag, or "" when it is not known. */
    public function flag(): string
    {
        $code = strtoupper((string) $this->country);

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? (string) mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65) : '';
    }

    /** "/memoirs" of "https://example.com/memoirs": the address without the website, for a short list. */
    public function path(): string
    {
        $path = (string) parse_url((string) $this->current_url, PHP_URL_PATH);

        return $path !== '' ? $path : '/';
    }

    /** @return BelongsTo<ChatVisitor, $this> */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(ChatVisitor::class, 'chat_visitor_id');
    }

    /** @return BelongsTo<ChatWidget, $this> */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(ChatWidget::class, 'chat_widget_id');
    }

    /** @return HasMany<ChatPageView, $this> */
    public function pageViews(): HasMany
    {
        return $this->hasMany(ChatPageView::class);
    }
}
