<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Database\Factories\BrandFactory;
use App\Modules\Departments\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The workspace. Owns websites, Facebook Pages, leads, files and members.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $website
 * @property string|null $logo_path
 * @property string|null $favicon_path
 * @property array<string, string>|null $colors
 * @property string $currency
 * @property string $timezone
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $mail_settings
 * @property bool $is_active
 * @property CarbonImmutable|null $identity_fetched_at
 * @property int|null $created_by
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'website', 'logo_path', 'favicon_path', 'colors',
        'currency', 'timezone', 'settings', 'mail_settings', 'is_active', 'identity_fetched_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'settings' => 'array',
            'mail_settings' => 'encrypted:array',
            'is_active' => 'boolean',
            'identity_fetched_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $brand): void {
            if (blank($brand->slug)) {
                $brand->slug = static::uniqueSlug($brand->name);
            }

            $brand->currency ??= config('liquify.default_currency');
            $brand->timezone ??= config('liquify.default_timezone');
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // --------------------------------------------------------------- relations

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<User, $this, Membership> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'brand_user')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'status', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---------------------------------------------------------------- settings

    /**
     * Per-brand setting with a sensible default (reveal limit, notification
     * emails, assignment rules). Global defaults live in config/liquify.php.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    public function revealLimitPerHour(): int
    {
        return (int) $this->setting('reveal_limit_per_hour', config('liquify.reveal_limit_per_hour'));
    }

    /** @return list<string> */
    public function notificationEmails(): array
    {
        return array_values(array_filter((array) $this->setting('notification_emails', [])));
    }

    /**
     * The brand's own SMTP account and auto-reply text, with defaults filled in.
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string, auto_reply: bool, auto_reply_subject: string, auto_reply_body: string}
     */
    public function mailSettings(): array
    {
        // A brand created a moment ago has no mail_settings attribute loaded yet.
        $settings = array_key_exists('mail_settings', $this->attributes) ? ($this->mail_settings ?? []) : [];

        return [
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (int) ($settings['port'] ?? 587) ?: 587,
            'encryption' => (string) ($settings['encryption'] ?? 'tls'),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'from_address' => (string) ($settings['from_address'] ?? ''),
            'from_name' => (string) ($settings['from_name'] ?? ''),
            'auto_reply' => (bool) ($settings['auto_reply'] ?? false),
            'auto_reply_subject' => (string) ($settings['auto_reply_subject'] ?? 'Thank you for contacting {brand}'),
            'auto_reply_body' => (string) ($settings['auto_reply_body'] ?? "Hi {name},\n\nThank you for your enquiry. A member of our team will be in touch shortly.\n\n{brand}"),
        ];
    }

    public function hasMailSettings(): bool
    {
        $settings = $this->mailSettings();

        return $settings['host'] !== '' && $settings['from_address'] !== '';
    }

    public function autoReplyEnabled(): bool
    {
        return $this->hasMailSettings() && $this->mailSettings()['auto_reply'];
    }

    /**
     * The colour the brand is shown in: its main colour, or its second one
     * when the main one is missing or says nothing (a logo on a transparent
     * background is often read as "white").
     */
    public function primaryColor(): ?string
    {
        foreach (['primary', 'secondary'] as $key) {
            $color = $this->colors[$key] ?? null;

            if (is_string($color) && self::colorSaysSomething($color)) {
                return $color;
            }
        }

        return null;
    }

    /** Nearly white and nearly black tell one brand from another no better than no colour at all. */
    public static function colorSaysSomething(string $hex): bool
    {
        if (! preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $parts)) {
            return false;
        }

        $channels = array_map('hexdec', array_slice($parts, 1));

        return min($channels) < 236 && max($channels) > 28;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }

    /** Small image for the brand chip: favicon, else logo, else nothing (the chip shows the initial). */
    public function avatarUrl(): ?string
    {
        return $this->faviconUrl() ?? $this->logoUrl();
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }

    public function url(string $path = ''): string
    {
        return url('/b/'.$this->slug.($path !== '' ? '/'.ltrim($path, '/') : ''));
    }
}
