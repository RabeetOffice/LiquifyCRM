<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\DeviceKind;
use App\Modules\Auth\Enums\DeviceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone or tablet one person signed in on. The browser carries a random
 * cookie (lq_device) and only its hash is kept here, so the same phone is
 * known again; an Admin approves or blocks it on Sign-in security. Blocking
 * covers every person who used that phone.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property DeviceKind $kind
 * @property string $name
 * @property DeviceStatus $status
 * @property string|null $user_agent
 * @property string|null $first_ip
 * @property string|null $last_ip
 * @property CarbonImmutable|null $last_seen_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $user
 * @property-read User|null $decider
 */
class SignInDevice extends Model
{
    /** The cookie that names this browser. */
    public const COOKIE = 'lq_device';

    protected $fillable = ['user_id', 'token_hash', 'kind', 'name', 'status', 'user_agent', 'first_ip', 'last_ip', 'last_seen_at', 'decided_by', 'decided_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'kind' => DeviceKind::class,
            'status' => DeviceStatus::class,
            'last_seen_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, DeviceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
