<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody who opened a brand's chat widget: a browser (the token in its storage), whoever it turns out to
 * be once they give a name, an email or a phone number. Email and phone are encrypted at rest. The visitor
 * is not a lead; `lead_id` is set only when they give contact details.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $chat_widget_id
 * @property string $visitor_token
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property int|null $lead_id
 * @property string|null $ip
 * @property string|null $country
 * @property string|null $city
 * @property string|null $region
 * @property string|null $isp
 * @property string|null $timezone
 * @property bool|null $is_proxy
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $device
 * @property int $visits_count
 * @property bool $is_banned
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 */
class ChatVisitor extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'chat_widget_id', 'visitor_token', 'name', 'email', 'phone', 'lead_id', 'ip', 'country', 'city', 'region', 'isp', 'timezone', 'is_proxy',
        'browser', 'os', 'device', 'visits_count', 'is_banned', 'first_seen_at', 'last_seen_at',
    ];

    protected $attributes = ['visits_count' => 1, 'is_banned' => false];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'visits_count' => 'integer',
            'is_banned' => 'boolean',
            'is_proxy' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** What the team calls them until they say who they are: "Visitor 1234". */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : 'Visitor '.$this->getKey();
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'chat_visitor_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<ChatWidget, $this> */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(ChatWidget::class, 'chat_widget_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
