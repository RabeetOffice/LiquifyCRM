<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A brand's live chat: the key its websites carry in one script line, the websites it may run on, how it
 * looks and what it asks before the chat starts. A brand with several websites lists them all on one widget.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $widget_key
 * @property list<string>|null $allowed_domains
 * @property array<string, string>|null $appearance
 * @property array<string, mixed>|null $prechat
 * @property bool $agents_see_visitors
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $creator
 */
class ChatWidget extends Model
{
    use BelongsToBrand;

    public const DEFAULT_APPEARANCE = [
        'color' => '#f97316',
        'position' => 'right',
        'title' => 'Chat with us',
        'welcome' => 'Hi! How can we help you today?',
        'offline' => 'We are away right now. Leave your details and we will get back to you.',
        'consent' => 'Your messages are kept so our team can reply. We do not share them.',
    ];

    /** What the visitor may be asked before the chat starts. Name and email or phone are what make a lead. */
    public const PRECHAT_FIELDS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone number',
        'message' => 'How can we help?',
    ];

    protected $fillable = ['brand_id', 'name', 'widget_key', 'allowed_domains', 'appearance', 'prechat', 'agents_see_visitors', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'appearance' => 'array',
            'prechat' => 'array',
            'agents_see_visitors' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** A key nobody has: "wk_" and 24 letters and digits. */
    public static function newKey(): string
    {
        do {
            $key = 'wk_'.Str::random(24);
        } while (self::query()->withoutGlobalScopes()->where('widget_key', $key)->exists());

        return $key;
    }

    /** The one line the website's footer carries. */
    public function snippet(): string
    {
        return '<script src="'.url('/chat/widget.js').'" data-key="'.$this->widget_key.'" async></script>';
    }

    public function look(string $key): string
    {
        $value = ($this->appearance ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_APPEARANCE[$key];
    }

    /** Text on the widget's colour: black or white, whichever reads better. */
    public function onColor(): string
    {
        $hex = ltrim($this->look('color'), '#');
        $luma = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;

        return $luma > 0.62 ? '#111111' : '#ffffff';
    }

    /** Whether the team sees who is on the website (Live visitors). On unless the brand switched it off. */
    public function tracks(): bool
    {
        return (($this->appearance ?? [])['track_visitors'] ?? '1') !== '0';
    }

    /** Whether the widget asks for this field before the chat starts. */
    public function asks(string $field): bool
    {
        return (bool) (($this->prechat['fields'] ?? [])[$field] ?? false);
    }

    public function asksFirst(): bool
    {
        return (bool) ($this->prechat['enabled'] ?? false);
    }

    /**
     * Whether a page at this origin ("https://www.example.com") may run the widget: the host is one of the
     * listed websites or a subdomain of one. A widget with no website listed runs nowhere.
     */
    public function allowsOrigin(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach ($this->allowed_domains ?? [] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Websites as people write them (one a line or separated by commas, with https:// and paths and www.)
     * turned into plain host names, without duplicates. A line that is not a host name is returned in
     * `invalid`.
     *
     * @return array{domains: list<string>, invalid: list<string>}
     */
    public static function parseDomains(string $text): array
    {
        $domains = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            $host = strtolower((string) (parse_url(str_contains($entry, '://') ? $entry : 'https://'.$entry, PHP_URL_HOST) ?? ''));
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host) === 1) {
                $domains[$host] = $host;
            } else {
                $invalid[] = $entry;
            }
        }

        return ['domains' => array_values($domains), 'invalid' => $invalid];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ChatVisitor, $this> */
    public function visitors(): HasMany
    {
        return $this->hasMany(ChatVisitor::class);
    }
}
