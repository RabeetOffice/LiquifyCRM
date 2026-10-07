<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\MembershipStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The link between a user and a brand, carrying the brand role. Saving a
 * membership keeps the spatie role for that brand (team) in sync, so the
 * permission table is always answered from this single source of truth.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $user_id
 * @property BrandRole $role
 * @property MembershipStatus $status
 * @property int|null $invited_by
 * @property CarbonImmutable|null $joined_at
 */
class Membership extends Pivot
{
    use BelongsToBrand;

    public $incrementing = true;

    protected $table = 'brand_user';

    protected $fillable = ['brand_id', 'user_id', 'role', 'status', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return [
            'role' => BrandRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $membership): void {
            $membership->syncPermissionRole();
        });

        static::deleted(function (self $membership): void {
            $membership->syncPermissionRole(remove: true);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * Mirror this membership into spatie/laravel-permission for the brand team.
     */
    public function syncPermissionRole(bool $remove = false): void
    {
        $user = $this->user ?? User::query()->find($this->user_id);

        if ($user === null) {
            return;
        }

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($this->brand_id);

        try {
            // Reload the roles relation under this team before syncing.
            $user->unsetRelation('roles');

            if ($remove || ! $this->isActive()) {
                $user->syncRoles([]);
            } else {
                $user->syncRoles([$this->role->value]);
            }

            $user->unsetRelation('roles');
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }
}
