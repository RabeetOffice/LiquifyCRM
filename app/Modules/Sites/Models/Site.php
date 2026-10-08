<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Sites\Database\Factories\SiteFactory;
use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Enums\SiteMode;
use App\Modules\Sites\Enums\SitePlatform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A brand website that sends its form leads to LiquifyCRM.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $url
 * @property string $domain
 * @property string $site_key
 * @property string $secret
 * @property SiteMode $mode
 * @property SitePlatform $platform
 * @property string|null $form_path
 * @property string|null $favicon_path
 * @property array<string, mixed>|null $pull_config
 * @property int|null $pull_cursor
 * @property CarbonImmutable|null $last_pulled_at
 * @property string|null $last_pull_note
 * @property CheckStatus $health_status
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_lead_at
 * @property CarbonImmutable|null $last_test_at
 * @property CarbonImmutable|null $quiet_notified_at
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Brand $brand
 */
class Site extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'brand_id', 'name', 'url', 'domain', 'site_key', 'secret', 'mode', 'platform', 'form_path',
        'favicon_path', 'pull_config', 'pull_cursor', 'last_pulled_at', 'last_pull_note', 'health_status',
        'last_checked_at', 'last_lead_at', 'last_test_at', 'quiet_notified_at', 'is_active', 'created_by',
    ];

    protected $hidden = ['secret', 'pull_config'];

    protected $attributes = [
        'mode' => 'push',
        'platform' => 'php',
        'health_status' => 'unknown',
        'is_active' => true,
        'form_path' => null,
        'favicon_path' => null,
        'pull_config' => null,
        'pull_cursor' => null,
        'last_pulled_at' => null,
        'last_pull_note' => null,
        'last_checked_at' => null,
        'last_lead_at' => null,
        'last_test_at' => null,
        'quiet_notified_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'pull_config' => 'encrypted:array',
            'mode' => SiteMode::class,
            'platform' => SitePlatform::class,
            'health_status' => CheckStatus::class,
            'last_pulled_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_lead_at' => 'datetime',
            'last_test_at' => 'datetime',
            'quiet_notified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $site): void {
            $site->url = self::cleanUrl($site->url);
            $site->domain = self::domainOf($site->url);

            if (blank($site->getAttributes()['site_key'] ?? null)) {
                $site->site_key = self::generateKey($site->domain);
            }

            if (blank($site->getAttributes()['secret'] ?? null)) {
                $site->secret = self::generateSecret();
            }
        });
    }

    // ------------------------------------------------------------- generators

    /** Short public key such as "ukpublishi_7f3a9c2e"; safe to show in an address. */
    public static function generateKey(string $domain): string
    {
        $prefix = Str::of($domain)->before('.')->replaceMatches('/[^a-z0-9]/i', '')->lower()->limit(10, '')->value() ?: 'site';

        do {
            $key = $prefix.'_'.Str::lower(Str::random(8));
        } while (self::query()->acrossBrands()->withTrashed()->where('site_key', $key)->exists());

        return $key;
    }

    public static function generateSecret(): string
    {
        return Str::random(48);
    }

    public static function cleanUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    public static function domainOf(string $url): string
    {
        $host = (string) parse_url(self::cleanUrl($url), PHP_URL_HOST);

        return mb_strtolower((string) preg_replace('/^www\./i', '', $host));
    }

    // --------------------------------------------------------------- relations

    /** @return HasMany<SiteCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(SiteCheck::class);
    }

    /** @return HasMany<InboundEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(InboundEvent::class, 'source_id')->where('source', InboundEvent::SOURCE_SITE);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ----------------------------------------------------------------- helpers

    /** Where the website posts its leads. */
    public function ingestUrl(): string
    {
        return url('/api/v1/ingest/site/'.$this->site_key);
    }

    /**
     * Form builders such as Elementor cannot sign a request, so their address
     * carries a token derived from the secret instead of the secret itself.
     */
    public function webhookToken(): string
    {
        return substr(hash_hmac('sha256', 'webhook:'.$this->site_key, $this->secret), 0, 40);
    }

    public function webhookUrl(): string
    {
        return $this->ingestUrl().'?token='.$this->webhookToken();
    }

    public function pageUrl(string $path): string
    {
        return $this->url.'/'.ltrim($path, '/');
    }

    public function formPath(): string
    {
        return filled($this->form_path) ? (string) $this->form_path : '/form-submission.php';
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }

    public function isPull(): bool
    {
        return $this->mode === SiteMode::Pull;
    }

    public function usesWebhook(): bool
    {
        return $this->mode === SiteMode::Push && $this->platform === SitePlatform::Elementor;
    }

    /** No lead for longer than the quiet limit (48 hours) after at least one arrived. */
    public function isQuiet(): bool
    {
        return $this->last_lead_at !== null
            && $this->last_lead_at->lt(now()->subHours((int) config('liquify.sites.quiet_hours', 48)));
    }

    /** "connected", "quiet", "waiting" or "paused": drives the badge on the Websites screen. */
    public function connectionState(): string
    {
        return match (true) {
            ! $this->is_active => 'paused',
            $this->last_lead_at === null => 'waiting',
            $this->isQuiet() => 'quiet',
            default => 'connected',
        };
    }

    /**
     * The optional LeadsHub agent on the website (hub-agent.php): used to read
     * leads in pull mode and for the email and database checks.
     *
     * @return array{url: string, key: string, secret: string}|null
     */
    public function agentConfig(): ?array
    {
        $agent = $this->pull_config['agent'] ?? null;

        if (! is_array($agent) || blank($agent['key'] ?? null) || blank($agent['secret'] ?? null)) {
            return null;
        }

        return [
            'url' => filled($agent['url'] ?? null) ? (string) $agent['url'] : $this->pageUrl('/hub-agent.php'),
            'key' => (string) $agent['key'],
            'secret' => (string) $agent['secret'],
        ];
    }

    /**
     * Direct access to the leads table of the website.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string, table: string}|null
     */
    public function databaseConfig(): ?array
    {
        $database = $this->pull_config['database'] ?? null;

        if (! is_array($database) || blank($database['host'] ?? null) || blank($database['database'] ?? null)) {
            return null;
        }

        return [
            'host' => (string) $database['host'],
            'port' => (int) ($database['port'] ?? 3306) ?: 3306,
            'database' => (string) $database['database'],
            'username' => (string) ($database['username'] ?? ''),
            'password' => (string) ($database['password'] ?? ''),
            'table' => (string) ($database['table'] ?? 'leads') ?: 'leads',
        ];
    }

    /** "agent", "database" or null when nothing is filled in yet. */
    public function pullDriver(): ?string
    {
        $preferred = $this->pull_config['driver'] ?? null;

        if ($preferred === 'database' && $this->databaseConfig() !== null) {
            return 'database';
        }

        if ($this->agentConfig() !== null) {
            return 'agent';
        }

        return $this->databaseConfig() !== null ? 'database' : null;
    }
}
