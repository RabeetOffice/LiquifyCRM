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
        'currency', 'timezone', 'settings', 'is_active', 'identity_fetched_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'settings' => 'array',
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

    public function primaryColor(): ?string
    {
        return $this->colors['primary'] ?? null;
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
