<?php

declare(strict_types=1);

namespace App\Modules\Meta\Models;

use App\Modules\Brands\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Facebook Page and the brand it is mapped to. Managed globally by the
 * Admins on the Integrations screen, so it is not brand-scoped; a Page with
 * no brand yet keeps its events waiting. The Instagram professional account
 * linked to the Page rides along: its direct messages go to the same brand.
 *
 * @property int $id
 * @property int|null $meta_connection_id
 * @property int|null $brand_id
 * @property string $type
 * @property string $external_id
 * @property string|null $instagram_id
 * @property string|null $instagram_username
 * @property string $name
 * @property string|null $access_token
 * @property list<string>|null $subscribed_fields
 * @property CarbonImmutable|null $subscribed_at
 * @property CarbonImmutable|null $last_event_at
 * @property string|null $error
 * @property-read Brand|null $brand
 */
class MetaSource extends Model
{
    public const TYPE_PAGE = 'facebook_page';

    /** What every Page is subscribed to: Lead Ads and Messenger, with the Page's own replies written on Facebook (echoes). */
    public const FIELDS = ['leadgen', 'messages', 'messaging_postbacks', 'message_deliveries', 'message_reads', 'message_echoes'];

    protected $fillable = [
        'meta_connection_id', 'brand_id', 'type', 'external_id', 'instagram_id', 'instagram_username', 'name', 'access_token',
        'subscribed_fields', 'subscribed_at', 'last_event_at', 'error',
    ];

    protected $hidden = ['access_token'];

    protected $attributes = [
        'type' => self::TYPE_PAGE, 'brand_id' => null, 'access_token' => null, 'subscribed_fields' => null,
        'subscribed_at' => null, 'last_event_at' => null, 'error' => null,
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'subscribed_fields' => 'array',
            'subscribed_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<MetaConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function isSubscribed(): bool
    {
        return $this->subscribed_at !== null && $this->error === null;
    }

    /**
     * The Instagram account linked to this Page, as "@account". Read from the
     * raw attributes so a server that has not run "Update system" yet (no such
     * column) simply has none.
     */
    public function instagramHandle(): ?string
    {
        $username = $this->getAttributes()['instagram_username'] ?? null;

        return is_string($username) && $username !== '' ? '@'.$username : null;
    }

    public function instagramId(): ?string
    {
        $id = $this->getAttributes()['instagram_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
