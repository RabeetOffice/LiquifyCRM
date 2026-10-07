<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Database\Factories\UserFactory;
use App\Modules\Auth\Exceptions\ProtectedUserException;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Models\DepartmentUser;
use Carbon\CarbonImmutable;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property GlobalRole|null $role
 * @property bool $is_protected
 * @property MembershipStatus $status
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property string|null $timezone
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $app_authentication_secret
 * @property array<int, string>|null $app_authentication_recovery_codes
 * @property bool $has_email_authentication
 * @property int|null $created_by
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasDefaultTenant, HasName, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'is_protected', 'status',
        'phone', 'avatar_path', 'timezone', 'created_by',
    ];

    protected $hidden = [
        'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => GlobalRole::class,
            'is_protected' => 'boolean',
            'status' => MembershipStatus::class,
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'has_email_authentication' => 'boolean',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected static function booted(): void
    {
        // The last Super Admin can never be removed or demoted, whoever asks.
        static::saving(function (self $user): void {
            if ($user->isSuperAdmin()) {
                $user->is_protected = true;
            }

            if (! $user->exists || ! $user->isDirty('role')) {
                return;
            }

            $wasSuperAdmin = $user->getRawOriginal('role') === GlobalRole::SuperAdmin->value;

            if ($wasSuperAdmin && ! $user->isSuperAdmin() && static::superAdmins()->count() <= 1) {
                throw ProtectedUserException::lastSuperAdmin();
            }
        });

        static::deleting(function (self $user): void {
            if ($user->isSuperAdmin() && static::superAdmins()->count() <= 1) {
                throw ProtectedUserException::lastSuperAdmin();
            }
        });
    }

    // ------------------------------------------------------------------ roles

    public function isSuperAdmin(): bool
    {
        return $this->role === GlobalRole::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === GlobalRole::Admin;
    }

    /** Super Admin or Admin: sees every brand. */
    public function isGlobalAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin();
    }

    public function isSuspended(): bool
    {
        return $this->status === MembershipStatus::Suspended;
    }

    public function isActive(): bool
    {
        return ! $this->isSuspended();
    }

    /** @return Builder<static> */
    public static function superAdmins(): Builder
    {
        return static::query()->where('role', GlobalRole::SuperAdmin->value);
    }

    // ------------------------------------------------------------- memberships

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<Brand, $this, Membership> */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_user')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'status', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function membershipFor(Brand|int $brand): ?Membership
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        if ($this->relationLoaded('memberships')) {
            return $this->memberships->firstWhere('brand_id', $brandId);
        }

        return $this->memberships()->acrossBrands()->where('brand_id', $brandId)->first();
    }

    public function brandRole(Brand|int $brand): ?BrandRole
    {
        return $this->membershipFor($brand)?->role;
    }

    public function isMemberOf(Brand|int $brand): bool
    {
        if ($this->isGlobalAdmin()) {
            return true;
        }

        $membership = $this->membershipFor($brand);

        return $membership !== null && $membership->status === MembershipStatus::Active;
    }

    public function isManagerIn(Brand|int $brand): bool
    {
        return $this->brandRole($brand) === BrandRole::Manager;
    }

    public function isAgentIn(Brand|int $brand): bool
    {
        return $this->brandRole($brand) === BrandRole::Agent;
    }

    // ------------------------------------------------------------- departments

    /** @return BelongsToMany<Department, $this, DepartmentUser> */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')
            ->using(DepartmentUser::class)
            ->withPivot(['id', 'role', 'created_by'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Department, $this, DepartmentUser> */
    public function managedDepartments(): BelongsToMany
    {
        return $this->departments()->wherePivot('role', DepartmentRole::Manager->value);
    }

    /**
     * Ids of the departments this user manages inside one brand.
     *
     * @return list<int>
     */
    public function managedDepartmentIds(Brand|int $brand): array
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        return $this->managedDepartments()
            ->acrossBrands()
            ->where('departments.brand_id', $brandId)
            ->pluck('departments.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ids of every department (managed or worked in) inside one brand.
     *
     * @return list<int>
     */
    public function departmentIds(Brand|int $brand): array
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        return $this->departments()
            ->acrossBrands()
            ->where('departments.brand_id', $brandId)
            ->pluck('departments.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function managesDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        return $this->managedDepartments()->acrossBrands()->where('departments.id', $departmentId)->exists();
    }

    public function belongsToDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        return $this->departments()->acrossBrands()->where('departments.id', $departmentId)->exists();
    }

    // ---------------------------------------------------------------- Filament

    public function canAccessPanel(Panel $panel): bool
    {
        // Soft-deleted users never reach the guard, so only suspension matters here.
        if ($this->isSuspended()) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return $this->isGlobalAdmin();
        }

        if ($this->isGlobalAdmin()) {
            return true;
        }

        return $this->memberships()
            ->acrossBrands()
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    /** @return Collection<int, Brand> */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isGlobalAdmin()) {
            return Brand::query()->where('is_active', true)->orderBy('name')->get();
        }

        return $this->brands()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->where('brands.is_active', true)
            ->orderBy('brands.name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Brand || ! $tenant->is_active) {
            return false;
        }

        if ($this->isGlobalAdmin()) {
            return true;
        }

        return $this->memberships()
            ->acrossBrands()
            ->where('brand_id', $tenant->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    // --------------------------------------------------------- two-factor auth

    /** Super Admins and Admins must set up an authenticator app. */
    public function requiresTwoFactor(): bool
    {
        return $this->isGlobalAdmin();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
