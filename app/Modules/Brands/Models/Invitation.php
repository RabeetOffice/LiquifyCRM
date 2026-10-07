<?php

declare(strict_types=1);

namespace App\Modules\Brands\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Departments\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A signed invite link for a new Manager or Agent.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $department_id
 * @property string $email
 * @property string|null $name
 * @property BrandRole $role
 * @property DepartmentRole|null $department_role
 * @property string $token
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 */
class Invitation extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'department_id', 'email', 'name', 'role', 'department_role',
        'token', 'invited_by', 'expires_at', 'accepted_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'role' => BrandRole::class,
            'department_role' => DepartmentRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->token ??= static::generateToken();
            $invitation->expires_at ??= now()->addDays((int) config('liquify.invitation_expiry_days'));
            $invitation->email = Str::lower(trim($invitation->email));
        });
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::query()->acrossBrands()->where('token', $token)->exists());

        return $token;
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @param  Builder<static>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    public function acceptUrl(): string
    {
        return route('invitations.show', ['token' => $this->token]);
    }
}
