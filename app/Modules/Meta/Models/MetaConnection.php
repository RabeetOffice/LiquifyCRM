<?php

declare(strict_types=1);

namespace App\Modules\Meta\Models;

use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one Facebook connection for all brands: a Facebook login, or a
 * Business Manager System User token that never expires.
 *
 * @property int $id
 * @property string $fb_user_id
 * @property string|null $fb_user_name
 * @property string $user_token
 * @property bool $is_system_user
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_refreshed_at
 * @property string|null $error
 * @property int|null $connected_by
 * @property CarbonImmutable|null $created_at
 */
class MetaConnection extends Model
{
    protected $fillable = ['fb_user_id', 'fb_user_name', 'user_token', 'is_system_user', 'expires_at', 'last_refreshed_at', 'error', 'connected_by'];

    protected $hidden = ['user_token'];

    protected $attributes = ['is_system_user' => false, 'expires_at' => null, 'last_refreshed_at' => null, 'error' => null];

    protected function casts(): array
    {
        return [
            'user_token' => 'encrypted',
            'is_system_user' => 'boolean',
            'expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    /** @return HasMany<MetaSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(MetaSource::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresSoon(int $days = 15): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now()->addDays($days));
    }
}
